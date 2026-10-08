<?php declare(strict_types=1);

namespace App\Logging;

use Closure;

class LogcoreTransport
{
    private static ?LogcoreTransport $instance = null;
    private static ?Closure $captureHandler = null;

    /** @var array<int, array<string, mixed>> */
    private array $buffer = [];
    private int $consecutiveFailures = 0;
    private bool $shutdownRegistered = false;

    public function __construct()
    {
        $this->registerShutdown();
    }

    public static function getInstance(): self
    {
        if (self::$instance === null) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    public static function setCaptureHandler(?Closure $handler): void
    {
        self::$captureHandler = $handler;
    }

    public static function isCaptureActive(): bool
    {
        return self::$captureHandler !== null;
    }

    /**
     * @param array<string, mixed> $entry
     */
    public function push(array $entry): void
    {
        // Buffer cap: 1000 entries; when full the oldest are dropped
        if (count($this->buffer) >= 1000) {
            array_shift($this->buffer);
        }

        $this->buffer[] = $entry;

        // Batching: flush at 20 buffered entries
        if (count($this->buffer) >= 20) {
            $this->flush();
        }
    }

    public function flush(): void
    {
        if (empty($this->buffer)) {
            return;
        }

        // Circuit breaker: after 10 consecutive failed flushes, stop sending and empty the buffer
        if ($this->consecutiveFailures >= 10) {
            $this->buffer = [];
            return;
        }

        // If capture handler is set, pass the envelope directly without network request
        if (self::$captureHandler !== null) {
            $batch = $this->buffer;
            $this->buffer = [];
            $envelope = [
                'schema_version' => 1,
                'entries' => $batch,
            ];
            call_user_func(self::$captureHandler, $envelope);
            return;
        }

        $enabled = env('LOGCORE_ENABLED');
        if ($enabled === false || $enabled === 'false' || $enabled === '0') {
            $this->buffer = [];
            return;
        }

        $key = env('LOGCORE_KEY');
        if (empty($key) || !is_string($key)) {
            $this->buffer = [];
            return;
        }

        $url = env('LOGCORE_URL', 'https://logcore-dev-352942961463.us-east4.run.app');
        $endpoint = rtrim((string) $url, '/') . '/v1/logs';

        while (!empty($this->buffer)) {
            $chunk = array_splice($this->buffer, 0, 200);
            $envelope = [
                'schema_version' => 1,
                'entries' => $chunk,
            ];
            $body = json_encode($envelope, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
            if ($body === false) {
                continue;
            }

            $success = $this->sendWithRetry($endpoint, (string) $key, $body);
            if ($success) {
                $this->consecutiveFailures = 0;
            } else {
                $this->consecutiveFailures++;
                if ($this->consecutiveFailures >= 10) {
                    $this->buffer = [];
                    break;
                }
            }
        }
    }

    protected function sendWithRetry(string $endpoint, string $key, string $body): bool
    {
        $maxAttempts = 4;
        for ($attempt = 0; $attempt < $maxAttempts; $attempt++) {
            if ($attempt > 0) {
                $delayMicros = (int) (2 ** $attempt * 250000);
                usleep($delayMicros);
            }

            $result = $this->executeHttpRequest($endpoint, $key, $body);
            if ($result === null) {
                // Network or transport error, retry
                continue;
            }

            $statusCode = $result['status'];
            $responseBody = $result['body'];

            if ($statusCode >= 200 && $statusCode < 300) {
                return true;
            }

            if ($statusCode === 429 || $statusCode >= 500) {
                // Retryable server or rate-limit error
                continue;
            }

            // Permanent rejection (4xx except 429) - drop batch and echo server reason to stderr
            fwrite(STDERR, sprintf("[Logcore] Batch rejected by %s (HTTP %d): %s\n", $endpoint, $statusCode, $responseBody));
            return false;
        }

        return false;
    }

    /**
     * @return array{status: int, body: string}|null
     */
    protected function executeHttpRequest(string $endpoint, string $key, string $body): ?array
    {
        if (extension_loaded('curl')) {
            $ch = curl_init($endpoint);
            if ($ch === false) {
                return null;
            }

            curl_setopt_array($ch, [
                CURLOPT_POST => true,
                CURLOPT_POSTFIELDS => $body,
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_HTTPHEADER => [
                    'Content-Type: application/json',
                    'x-api-key: ' . $key,
                ],
                CURLOPT_TIMEOUT => 5,
                CURLOPT_CONNECTTIMEOUT => 2,
            ]);

            $response = curl_exec($ch);
            if ($response === false) {
                curl_close($ch);
                return null;
            }

            $statusCode = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
            curl_close($ch);

            return [
                'status' => $statusCode,
                'body' => is_string($response) ? $response : '',
            ];
        }

        // Fallback to PHP streams
        $context = stream_context_create([
            'http' => [
                'method' => 'POST',
                'header' => "Content-Type: application/json\r\n" .
                            "x-api-key: " . $key . "\r\n",
                'content' => $body,
                'timeout' => 5,
                'ignore_errors' => true,
            ],
        ]);

        $stream = @fopen($endpoint, 'r', false, $context);
        if ($stream === false) {
            return null;
        }

        $responseBody = stream_get_contents($stream);
        $meta = stream_get_meta_data($stream);
        fclose($stream);

        $status = 0;
        if (isset($meta['wrapper_data']) && is_array($meta['wrapper_data'])) {
            foreach ($meta['wrapper_data'] as $headerLine) {
                if (preg_match('#HTTP/\S+\s+(\d{3})#', (string) $headerLine, $matches)) {
                    $status = (int) $matches[1];
                    break;
                }
            }
        }

        return [
            'status' => $status,
            'body' => is_string($responseBody) ? $responseBody : '',
        ];
    }

    private function registerShutdown(): void
    {
        if (!$this->shutdownRegistered) {
            $this->shutdownRegistered = true;
            register_shutdown_function(function () {
                $this->flush();
            });
        }
    }
}
