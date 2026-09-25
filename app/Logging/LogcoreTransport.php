<?php declare(strict_types=1);

namespace App\Logging;

class LogcoreTransport
{
    private static ?self $instance = null;

    /**
     * @var array<int, array<string, mixed>>
     */
    private array $buffer = [];

    private int $consecutiveFailures = 0;

    /**
     * @var (callable(array{schema_version: int, entries: array<int, array<string, mixed>>}): bool)|null
     */
    private $sender = null;

    private bool $registeredShutdown = false;

    public function __construct()
    {
        if (!$this->registeredShutdown) {
            register_shutdown_function(function () {
                $this->flush();
            });
            $this->registeredShutdown = true;
        }
    }

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
    }

    /**
     * For testing/mocking: set a custom sender callback.
     *
     * @param (callable(array{schema_version: int, entries: array<int, array<string, mixed>>}): bool)|null $sender
     */
    public function setSender(?callable $sender): void
    {
        $this->sender = $sender;
    }

    public function isEnabled(): bool
    {
        $enabled = env('LOGCORE_ENABLED', true);
        if ($enabled === false || $enabled === 'false' || $enabled === '0' || $enabled === 0) {
            return false;
        }

        return true;
    }

    public function getEndpointUrl(): string
    {
        $url = (string) env('LOGCORE_URL', 'https://logcore-dev-352942961463.us-east4.run.app');
        $url = rtrim(trim($url), '/');
        if ($url !== '' && !str_ends_with($url, '/v1/logs')) {
            $url .= '/v1/logs';
        }

        return $url;
    }

    public function getApiKey(): string
    {
        return (string) env('LOGCORE_KEY', '');
    }

    /**
     * Push an entry into buffer.
     *
     * @param array<string, mixed> $entry
     */
    public function push(array $entry): void
    {
        if (!$this->isEnabled()) {
            return;
        }

        if ($this->consecutiveFailures >= 10) {
            $this->buffer = [];
            return;
        }

        // Buffer cap: 1000 entries (drop oldest)
        if (count($this->buffer) >= 1000) {
            array_shift($this->buffer);
        }

        $this->buffer[] = $entry;

        // Batching threshold: flush at 20 entries
        if (count($this->buffer) >= 20) {
            $this->flush();
        }
    }

    /**
     * Flush buffered entries.
     */
    public function flush(): void
    {
        if (empty($this->buffer) || !$this->isEnabled()) {
            $this->buffer = [];
            return;
        }

        if ($this->consecutiveFailures >= 10) {
            $this->buffer = [];
            return;
        }

        $entriesToSend = $this->buffer;
        $this->buffer = [];

        // Chunk into at most 200 entries per HTTP request
        $chunks = array_chunk($entriesToSend, 200);
        foreach ($chunks as $chunk) {
            $payload = [
                'schema_version' => 1,
                'entries' => $chunk,
            ];
            $this->sendPayload($payload);
        }
    }

    /**
     * @param array{schema_version: int, entries: array<int, array<string, mixed>>} $payload
     */
    public function sendPayload(array $payload): bool
    {
        if ($this->sender !== null) {
            try {
                $result = ($this->sender)($payload);
                if ($result) {
                    $this->consecutiveFailures = 0;
                } else {
                    $this->consecutiveFailures++;
                }
                return $result;
            } catch (\Throwable) {
                $this->consecutiveFailures++;
                return false;
            }
        }

        $url = $this->getEndpointUrl();
        $apiKey = $this->getApiKey();

        if (empty($url) || empty($apiKey)) {
            // Cannot send without destination or key in production
            return false;
        }

        $jsonData = json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        if ($jsonData === false) {
            return false;
        }

        $maxRetries = 3;
        for ($attempt = 0; $attempt <= $maxRetries; $attempt++) {
            if ($attempt > 0) {
                // Exponential backoff: 2^attempt * 250ms
                $backoffUs = (int) ((2 ** ($attempt - 1)) * 250000);
                usleep($backoffUs);
            }

            try {
                $ch = curl_init();
                curl_setopt_array($ch, [
                    CURLOPT_URL => $url,
                    CURLOPT_POST => true,
                    CURLOPT_POSTFIELDS => $jsonData,
                    CURLOPT_HTTPHEADER => [
                        'Content-Type: application/json',
                        'x-api-key: ' . $apiKey,
                        'User-Agent: logcore-laravel/1.0',
                    ],
                    CURLOPT_RETURNTRANSFER => true,
                    CURLOPT_CONNECTTIMEOUT => 2,
                    CURLOPT_TIMEOUT => 4,
                    CURLOPT_SSL_VERIFYPEER => true,
                ]);

                $response = curl_exec($ch);
                $errno = curl_errno($ch);
                $error = curl_error($ch);
                $httpCode = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
                curl_close($ch);

                if ($errno === 0 && $httpCode >= 200 && $httpCode < 300) {
                    $this->consecutiveFailures = 0;
                    return true;
                }

                // Permanent rejection: 4xx except 429
                if ($httpCode >= 400 && $httpCode < 500 && $httpCode !== 429) {
                    $this->consecutiveFailures++;
                    $msg = is_string($response) ? $response : $error;
                    fwrite(STDERR, sprintf("[Logcore] Server rejected batch (HTTP %d): %s\n", $httpCode, $msg));
                    return false;
                }

                // Retryable: 5xx, 429, or network failure ($errno !== 0)
                if ($attempt === $maxRetries) {
                    $this->consecutiveFailures++;
                    return false;
                }
            } catch (\Throwable) {
                if ($attempt === $maxRetries) {
                    $this->consecutiveFailures++;
                    return false;
                }
            }
        }

        return false;
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function getBuffer(): array
    {
        return $this->buffer;
    }

    public function getConsecutiveFailures(): int
    {
        return $this->consecutiveFailures;
    }

    public function resetCircuitBreaker(): void
    {
        $this->consecutiveFailures = 0;
    }
}
