<?php declare(strict_types=1);

namespace App\Logging;

use Throwable;

class LogcoreTransport
{
    private static ?LogcoreTransport $instance = null;

    private string $endpoint;
    private ?string $apiKey;
    private bool $enabled;
    /** @var array<int, array<string, mixed>> */
    private array $buffer = [];
    private int $consecutiveFailures = 0;
    private bool $circuitBroken = false;
    /** @var (callable(string, array<string, string>, string): array{statusCode: int, body: string, error?: string})|null */
    private $httpClient = null;
    private bool $shutdownRegistered = false;
    private float $retryDelayMultiplier = 1.0;

    public function __construct(
        ?string $endpoint = null,
        ?string $apiKey = null,
        ?bool $enabled = null
    ) {
        $this->endpoint = $endpoint ?? (env('LOGCORE_URL') ?: 'https://logcore-dev-352942961463.us-east4.run.app');
        $this->apiKey = $apiKey ?? (env('LOGCORE_KEY') ?: null);
        $enabledEnv = env('LOGCORE_ENABLED');
        $this->enabled = $enabled ?? ($enabledEnv !== null ? filter_var($enabledEnv, FILTER_VALIDATE_BOOLEAN) : true);

        $this->registerShutdown();
    }

    public static function getInstance(): self
    {
        if (self::$instance === null) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    public static function setInstance(?self $instance): void
    {
        self::$instance = $instance;
    }

    /**
     * @param (callable(string, array<string, string>, string): array{statusCode: int, body: string, error?: string})|null $client
     */
    public function setHttpClient(?callable $client): void
    {
        $this->httpClient = $client;
    }

    public function setRetryDelayMultiplier(float $multiplier): void
    {
        $this->retryDelayMultiplier = $multiplier;
    }

    private function sleepForAttempt(int $attempt): void
    {
        if ($this->retryDelayMultiplier <= 0.0) {
            return;
        }
        $microseconds = (int) ((2 ** $attempt) * 250000 * $this->retryDelayMultiplier);
        if ($microseconds > 0) {
            usleep($microseconds);
        }
    }

    public function isEnabled(): bool
    {
        return $this->enabled;
    }

    public function isCircuitBroken(): bool
    {
        return $this->circuitBroken;
    }

    public function getConsecutiveFailures(): int
    {
        return $this->consecutiveFailures;
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function getBuffer(): array
    {
        return $this->buffer;
    }

    public function clearBuffer(): void
    {
        $this->buffer = [];
    }

    public function getEndpoint(): string
    {
        return $this->endpoint;
    }

    /**
     * @param array<string, mixed> $entry
     */
    public function push(array $entry): void
    {
        if (!$this->enabled) {
            return;
        }

        if ($this->circuitBroken) {
            return;
        }

        if (count($this->buffer) >= 1000) {
            array_shift($this->buffer);
        }

        $this->buffer[] = $entry;

        if (count($this->buffer) >= 20) {
            $this->flush();
        }
    }

    public function flush(): void
    {
        if (!$this->enabled || empty($this->buffer)) {
            return;
        }

        if ($this->circuitBroken) {
            $this->buffer = [];
            return;
        }

        while (!empty($this->buffer)) {
            $batch = array_splice($this->buffer, 0, 200);
            $this->sendBatch($batch);
        }
    }

    /**
     * @param array<int, array<string, mixed>> $entries
     */
    public function sendBatch(array $entries): bool
    {
        if (empty($entries)) {
            return true;
        }

        $payload = [
            'schema_version' => 1,
            'entries' => $entries,
        ];

        $json = json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        if ($json === false) {
            fwrite(STDERR, "[Logcore] JSON encoding failed for log entries\n");
            return false;
        }

        $url = rtrim($this->endpoint, '/') . '/v1/logs';
        $maxRetries = 3;

        for ($attempt = 0; $attempt <= $maxRetries; $attempt++) {
            if ($this->httpClient !== null) {
                try {
                    $response = ($this->httpClient)($url, [
                        'Content-Type' => 'application/json',
                        'x-api-key' => $this->apiKey ?? '',
                    ], $json);

                    $statusCode = $response['statusCode'];
                    $body = $response['body'];
                    $error = $response['error'] ?? null;

                    if ($error !== null) {
                        if ($attempt < $maxRetries) {
                            $this->sleepForAttempt($attempt);
                            continue;
                        }
                        $this->recordFailure("Client error: {$error}");
                        return false;
                    }

                    if ($statusCode >= 200 && $statusCode < 300) {
                        $this->recordSuccess();
                        return true;
                    }

                    if ($statusCode === 429 || $statusCode >= 500) {
                        if ($attempt < $maxRetries) {
                            $this->sleepForAttempt($attempt);
                            continue;
                        }
                        $this->recordFailure("HTTP {$statusCode}: {$body}");
                        return false;
                    }

                    $this->recordFailure("HTTP {$statusCode}: {$body}");
                    fwrite(STDERR, "[Logcore] Permanent delivery rejection (HTTP {$statusCode}): {$body}\n");
                    return false;
                } catch (Throwable $e) {
                    if ($attempt < $maxRetries) {
                        $this->sleepForAttempt($attempt);
                        continue;
                    }
                    $this->recordFailure("Exception: " . $e->getMessage());
                    return false;
                }
            }

            try {
                $ch = curl_init();
                curl_setopt_array($ch, [
                    CURLOPT_URL => $url,
                    CURLOPT_POST => true,
                    CURLOPT_POSTFIELDS => $json,
                    CURLOPT_HTTPHEADER => [
                        'Content-Type: application/json',
                        'x-api-key: ' . ($this->apiKey ?? ''),
                    ],
                    CURLOPT_RETURNTRANSFER => true,
                    CURLOPT_TIMEOUT => 5,
                    CURLOPT_CONNECTTIMEOUT => 2,
                    CURLOPT_NOSIGNAL => 1,
                ]);

                $body = curl_exec($ch);
                $errno = curl_errno($ch);
                $curlError = curl_error($ch);
                $httpCode = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
                curl_close($ch);

                if ($errno !== 0) {
                    if ($attempt < $maxRetries) {
                        $this->sleepForAttempt($attempt);
                        continue;
                    }
                    $this->recordFailure("cURL error ({$errno}): {$curlError}");
                    return false;
                }

                if ($httpCode >= 200 && $httpCode < 300) {
                    $this->recordSuccess();
                    return true;
                }

                if ($httpCode === 429 || $httpCode >= 500) {
                    if ($attempt < $maxRetries) {
                        $this->sleepForAttempt($attempt);
                        continue;
                    }
                    $this->recordFailure("HTTP {$httpCode}: " . (string) $body);
                    return false;
                }

                $this->recordFailure("HTTP {$httpCode}: " . (string) $body);
                fwrite(STDERR, "[Logcore] Permanent delivery rejection (HTTP {$httpCode}): " . (string) $body . "\n");
                return false;
            } catch (Throwable $e) {
                if ($attempt < $maxRetries) {
                    $this->sleepForAttempt($attempt);
                    continue;
                }
                $this->recordFailure("Exception: " . $e->getMessage());
                return false;
            }
        }

        return false;
    }

    private function recordSuccess(): void
    {
        $this->consecutiveFailures = 0;
        $this->circuitBroken = false;
    }

    private function recordFailure(string $reason): void
    {
        $this->consecutiveFailures++;
        if ($this->consecutiveFailures >= 10) {
            $this->circuitBroken = true;
            $this->buffer = [];
            fwrite(STDERR, "[Logcore] Circuit breaker tripped after 10 consecutive failures. Last error: {$reason}\n");
        }
    }

    private function registerShutdown(): void
    {
        if ($this->shutdownRegistered) {
            return;
        }
        $this->shutdownRegistered = true;

        register_shutdown_function(function () {
            try {
                $this->flush();
            } catch (Throwable) {
                // Suppress shutdown exception
            }
        });
    }
}
