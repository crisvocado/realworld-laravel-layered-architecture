<?php declare(strict_types=1);

namespace App\Logging;

class LogcoreTransport
{
    private const MAX_BUFFER_SIZE = 1000;
    private const BATCH_THRESHOLD = 20;
    private const MAX_BATCH_SIZE = 200;
    private const MAX_RETRIES = 3;
    private const CIRCUIT_BREAKER_THRESHOLD = 10;

    /**
     * @var list<array<string, mixed>>
     */
    private static array $buffer = [];

    /**
     * @var array<string, mixed>|null
     */
    private static ?array $lastEmittedEntry = null;

    private static int $consecutiveFailures = 0;
    private static bool $shutdownRegistered = false;

    /**
     * Optional custom HTTP handler for testing/stubbing without network calls.
     * @var (callable(string, string, string): array{status: int, body: string, error: string})|null
     */
    private static $httpHandler = null;

    /**
     * Check if Logcore reporting is enabled.
     */
    public static function isEnabled(): bool
    {
        $enabled = env('LOGCORE_ENABLED');
        if ($enabled !== null) {
            if (is_bool($enabled)) {
                return $enabled;
            }
            $lower = strtolower((string) $enabled);
            if (in_array($lower, ['false', '0', 'off', 'no'], true)) {
                return false;
            }
        }

        return true;
    }

    /**
     * Push a formatted entry into the transport buffer.
     *
     * @param array<string, mixed> $entry
     */
    public static function push(array $entry): void
    {
        self::initShutdownHook();

        self::$lastEmittedEntry = $entry;
        self::$buffer[] = $entry;

        // Cap buffer to 1000 entries; drop oldest if full
        while (count(self::$buffer) > self::MAX_BUFFER_SIZE) {
            array_shift(self::$buffer);
        }

        // Flush automatically if buffer reaches threshold
        if (count(self::$buffer) >= self::BATCH_THRESHOLD) {
            self::flush();
        }
    }

    /**
     * Flush all buffered log entries to the Logcore endpoint.
     */
    public static function flush(): void
    {
        if (empty(self::$buffer)) {
            return;
        }

        // Circuit breaker: after 10 consecutive failed flushes, drop buffer
        if (self::$consecutiveFailures >= self::CIRCUIT_BREAKER_THRESHOLD) {
            fwrite(STDERR, "[Logcore] Circuit breaker open (10 consecutive failures). Dropping " . count(self::$buffer) . " buffered entries.\n");
            self::$buffer = [];

            return;
        }

        $url = (string) (env('LOGCORE_URL') ?: (function_exists('config') ? config('logging.logcore.url', '') : ''));
        $key = (string) (env('LOGCORE_KEY') ?: (function_exists('config') ? config('logging.logcore.key', '') : ''));

        // If URL or key is missing and no test handler is set, cannot deliver over HTTP
        if (empty($url) && self::$httpHandler === null) {
            return;
        }

        while (!empty(self::$buffer)) {
            $batch = array_splice(self::$buffer, 0, self::MAX_BATCH_SIZE);
            if (empty($batch)) {
                break;
            }

            $success = self::sendBatch($batch, $url, $key);
            if (!$success) {
                // If a batch fails, stop processing further batches in this flush cycle
                break;
            }
        }
    }

    /**
     * Send a single batch of entries.
     *
     * @param list<array<string, mixed>> $batch
     */
    public static function sendBatch(array $batch, string $url, string $key): bool
    {
        $endpoint = rtrim($url, '/') . '/v1/logs';
        $envelope = [
            'schema_version' => 1,
            'entries' => $batch,
        ];

        $payload = json_encode($envelope, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        if ($payload === false) {
            fwrite(STDERR, "[Logcore] Failed to JSON encode log batch.\n");

            return false;
        }

        $attempts = 0;
        while ($attempts < self::MAX_RETRIES) {
            $attempts++;
            $result = self::performPost($endpoint, $payload, $key);
            $status = $result['status'];
            $body = $result['body'];

            if ($status >= 200 && $status < 300) {
                self::$consecutiveFailures = 0;

                return true;
            }

            // Retry on 429, 5xx, or network timeout/error (status 0)
            if ($status === 429 || $status >= 500 || $status === 0) {
                if ($attempts < self::MAX_RETRIES) {
                    $delayUs = (2 ** $attempts) * 250000;
                    usleep($delayUs);
                    continue;
                }
            } else {
                // Permanent 4xx client error (e.g., 400 Bad Request, 401, 422) - do not retry
                fwrite(STDERR, sprintf("[Logcore] Permanent rejection (HTTP %d): %s\n", $status, $body));
                break;
            }
        }

        self::$consecutiveFailures++;
        if (self::$consecutiveFailures >= self::CIRCUIT_BREAKER_THRESHOLD) {
            fwrite(STDERR, "[Logcore] Circuit breaker tripped after 10 consecutive flush failures. Emptying buffer.\n");
            self::$buffer = [];
        }

        return false;
    }

    /**
     * Register shutdown hook once to guarantee logs flush on PHP termination.
     */
    public static function initShutdownHook(): void
    {
        if (!self::$shutdownRegistered) {
            self::$shutdownRegistered = true;
            register_shutdown_function(static function (): void {
                try {
                    self::flush();
                } catch (\Throwable) {
                    // Never let transport failure disrupt application shutdown
                }
            });
        }
    }

    /**
     * Set a mock HTTP handler for testing.
     *
     * @param (callable(string, string, string): array{status: int, body: string, error: string})|null $handler
     */
    public static function setHttpHandlerForTesting(?callable $handler): void
    {
        self::$httpHandler = $handler;
    }

    /**
     * Get the current buffer for testing / verification.
     *
     * @return list<array<string, mixed>>
     */
    public static function getBuffer(): array
    {
        return self::$buffer;
    }

    /**
     * Get the last emitted entry (for probe / validation).
     *
     * @return array<string, mixed>|null
     */
    public static function getLastEmittedEntry(): ?array
    {
        return self::$lastEmittedEntry;
    }

    /**
     * Clear buffer and reset state (for testing).
     */
    public static function reset(): void
    {
        self::$buffer = [];
        self::$consecutiveFailures = 0;
        self::$lastEmittedEntry = null;
        self::$httpHandler = null;
    }

    /**
     * Perform HTTP POST request.
     *
     * @return array{status: int, body: string, error: string}
     */
    private static function performPost(string $endpoint, string $payload, string $apiKey): array
    {
        if (self::$httpHandler !== null) {
            return (self::$httpHandler)($endpoint, $payload, $apiKey);
        }

        if (function_exists('curl_init')) {
            $ch = curl_init($endpoint);
            if ($ch === false) {
                return ['status' => 0, 'body' => '', 'error' => 'curl_init failed'];
            }

            curl_setopt_array($ch, [
                CURLOPT_POST => true,
                CURLOPT_POSTFIELDS => $payload,
                CURLOPT_HTTPHEADER => [
                    'Content-Type: application/json',
                    'x-api-key: ' . $apiKey,
                    'User-Agent: logcore-laravel/1.0',
                ],
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_TIMEOUT => 3,
                CURLOPT_CONNECTTIMEOUT => 2,
                CURLOPT_SSL_VERIFYPEER => true,
            ]);

            $body = curl_exec($ch);
            $status = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
            $error = (string) curl_error($ch);
            curl_close($ch);

            return [
                'status' => $status,
                'body' => is_string($body) ? $body : '',
                'error' => $error,
            ];
        }

        // Fallback using PHP streams
        $context = stream_context_create([
            'http' => [
                'method' => 'POST',
                'header' => "Content-Type: application/json\r\n" .
                            "x-api-key: {$apiKey}\r\n" .
                            "User-Agent: logcore-laravel/1.0\r\n",
                'content' => $payload,
                'timeout' => 3,
                'ignore_errors' => true,
            ],
        ]);

        $body = @file_get_contents($endpoint, false, $context);
        $status = 0;
        if (isset($http_response_header) && is_array($http_response_header)) {
            if (preg_match('/HTTP\/\S+\s+(\d+)/', $http_response_header[0], $matches)) {
                $status = (int) $matches[1];
            }
        }

        return [
            'status' => $status,
            'body' => $body !== false ? $body : '',
            'error' => $body === false ? 'file_get_contents failed' : '',
        ];
    }
}
