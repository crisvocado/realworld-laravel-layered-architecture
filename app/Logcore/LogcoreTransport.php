<?php declare(strict_types=1);

namespace App\Logcore;

use Throwable;

class LogcoreTransport
{
    private static ?LogcoreTransport $instance = null;

    /** @var (callable(string, array<string, mixed>): void)|null */
    private static $captureCallback = null;

    /** @var array<int, array<string, mixed>> */
    private static array $buffer = [];

    private static int $consecutiveFailures = 0;
    private static bool $circuitBroken = false;

    private int $bufferCap = 1000;
    private int $batchSize = 20;
    private int $maxEntriesPerRequest = 200;
    private int $circuitBreakerThreshold = 10;

    private static bool $shutdownRegistered = false;

    public static function getInstance(): self
    {
        if (self::$instance === null) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    public static function resetInstance(): void
    {
        self::$instance = null;
        self::$captureCallback = null;
        self::$buffer = [];
        self::$consecutiveFailures = 0;
        self::$circuitBroken = false;
    }

    public function __construct()
    {
        if (self::$instance === null) {
            self::$instance = $this;
        }

        if (!self::$shutdownRegistered) {
            register_shutdown_function([$this, 'flush']);
            self::$shutdownRegistered = true;
        }
    }

    /**
     * @param (callable(string, array<string, mixed>): void)|null $callback
     */
    public function setCaptureCallback(?callable $callback): void
    {
        self::$captureCallback = $callback;
    }

    public function isEnabled(): bool
    {
        if (self::$captureCallback !== null) {
            return true;
        }

        $enabled = env('LOGCORE_ENABLED');
        if ($enabled !== null && ($enabled === false || $enabled === 'false' || $enabled === '0')) {
            return false;
        }

        $key = env('LOGCORE_KEY');
        $url = env('LOGCORE_URL');

        return !empty($key) && !empty($url);
    }

    /**
     * @param array<string, mixed> $entry
     */
    public function enqueue(array $entry): void
    {
        if (self::$circuitBroken && self::$captureCallback === null) {
            return;
        }

        if (!$this->isEnabled()) {
            return;
        }

        // Buffer cap: drop oldest if cap reached
        if (count(self::$buffer) >= $this->bufferCap) {
            array_shift(self::$buffer);
        }

        self::$buffer[] = $entry;

        if (count(self::$buffer) >= $this->batchSize) {
            $this->flush();
        }
    }

    public function flush(): void
    {
        if (empty(self::$buffer)) {
            return;
        }

        while (!empty(self::$buffer)) {
            $batch = array_splice(self::$buffer, 0, $this->maxEntriesPerRequest);

            $payload = [
                'schema_version' => 1,
                'entries' => $batch,
            ];

            $jsonPayload = json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
            if ($jsonPayload === false) {
                continue;
            }

            if (self::$captureCallback !== null) {
                try {
                    (self::$captureCallback)($jsonPayload, $payload);
                } catch (Throwable) {
                    // Do not break execution
                }
                continue;
            }

            $success = $this->sendBatchWithRetry($jsonPayload);
            if ($success) {
                self::$consecutiveFailures = 0;
                self::$circuitBroken = false;
            } else {
                self::$consecutiveFailures++;
                if (self::$consecutiveFailures >= $this->circuitBreakerThreshold) {
                    self::$circuitBroken = true;
                    self::$buffer = []; // empty buffer on breaker trip
                    file_put_contents('php://stderr', "[Logcore] Circuit breaker tripped after 10 consecutive failed flushes.\n");
                    break;
                }
            }
        }
    }

    private function sendBatchWithRetry(string $jsonPayload): bool
    {
        $rawUrl = (string) (env('LOGCORE_URL') ?: 'https://logcore-dev-352942961463.us-east4.run.app');
        $url = rtrim($rawUrl, '/') . '/v1/logs';
        $apiKey = (string) env('LOGCORE_KEY', '');

        if (empty($apiKey)) {
            return false;
        }

        $maxAttempts = 4; // 1 initial + up to 3 retries
        for ($attempt = 0; $attempt < $maxAttempts; $attempt++) {
            if ($attempt > 0) {
                // Exponential backoff: 2**attempt * 250ms (in microseconds)
                $sleepUs = (int) ((2 ** $attempt) * 250 * 1000);
                usleep($sleepUs);
            }

            $ch = curl_init();
            curl_setopt_array($ch, [
                CURLOPT_URL => $url,
                CURLOPT_POST => true,
                CURLOPT_POSTFIELDS => $jsonPayload,
                CURLOPT_HTTPHEADER => [
                    'Content-Type: application/json',
                    'x-api-key: ' . $apiKey,
                ],
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_TIMEOUT => 5,
                CURLOPT_CONNECTTIMEOUT => 3,
            ]);

            $responseBody = curl_exec($ch);
            $statusCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
            curl_close($ch);

            if ($responseBody !== false && $statusCode >= 200 && $statusCode < 300) {
                return true;
            }

            // Check permanent rejection: 4xx (except 429)
            if ($statusCode >= 400 && $statusCode < 500 && $statusCode !== 429) {
                file_put_contents('php://stderr', "[Logcore] Server rejected batch (HTTP {$statusCode}): " . ($responseBody ?: '') . "\n");
                return false; // do not retry permanent failures
            }
        }

        return false;
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function getBuffer(): array
    {
        return self::$buffer;
    }
}
