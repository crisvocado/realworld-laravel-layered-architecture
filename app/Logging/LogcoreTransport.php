<?php declare(strict_types=1);

namespace App\Logging;

class LogcoreTransport
{
    private const MAX_BUFFER_CAP = 1000;
    private const FLUSH_BATCH_SIZE = 20;
    private const MAX_REQUEST_ENTRIES = 200;
    private const CIRCUIT_BREAKER_THRESHOLD = 10;
    private const FLUSH_INTERVAL_SECONDS = 5;

    private static ?self $instance = null;

    /**
     * @var callable|null
     */
    private static $customSender = null;

    /**
     * @var array<string, mixed>|null
     */
    private static ?array $lastSentPayload = null;

    /**
     * @var array<int, array<string, mixed>>|null
     */
    private static ?array $lastSentEntries = null;

    /**
     * @var array<int, array<string, mixed>>
     */
    private array $buffer = [];

    private float $lastFlushTime;
    private int $consecutiveFailures = 0;

    private bool $enabled;
    private string $url;
    private string $key;
    private string $service;
    private string $env;

    public function __construct()
    {
        $this->lastFlushTime = microtime(true);

        $enabledEnv = env('LOGCORE_ENABLED', true);
        $this->enabled = filter_var($enabledEnv, FILTER_VALIDATE_BOOLEAN);
        $this->url = (string) env('LOGCORE_URL', 'https://logcore-dev-352942961463.us-east4.run.app');
        $this->key = (string) env('LOGCORE_KEY', '');
        $this->service = self::normalizeService((string) env('LOGCORE_SERVICE', env('APP_NAME', 'realworld-backend')));
        $this->env = self::normalizeEnv((string) env('LOGCORE_ENV', env('APP_ENV', 'local')));

        register_shutdown_function(function (): void {
            $this->flush();
        });
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
        self::$customSender = null;
        self::$lastSentPayload = null;
        self::$lastSentEntries = null;
    }

    /**
     * Set a custom sender for testing/validation.
     */
    public static function setSender(?callable $sender): void
    {
        self::$customSender = $sender;
    }

    /**
     * Get the last sent payload (for testing).
     *
     * @return array<string, mixed>|null
     */
    public static function getLastSentPayload(): ?array
    {
        return self::$lastSentPayload;
    }

    /**
     * Get the last sent entries (for testing).
     *
     * @return array<int, array<string, mixed>>|null
     */
    public static function getLastSentEntries(): ?array
    {
        return self::$lastSentEntries;
    }

    public function getService(): string
    {
        return $this->service;
    }

    public function getEnv(): string
    {
        return $this->env;
    }

    public function isEnabled(): bool
    {
        return $this->enabled;
    }

    /**
     * Enqueue a formatted log entry.
     *
     * @param array<string, mixed> $entry
     */
    public function enqueue(array $entry): void
    {
        // Circuit breaker check
        if ($this->consecutiveFailures >= self::CIRCUIT_BREAKER_THRESHOLD) {
            $this->buffer = [];
            return;
        }

        // Buffer cap: drop oldest if reached capacity
        if (count($this->buffer) >= self::MAX_BUFFER_CAP) {
            array_shift($this->buffer);
        }

        $this->buffer[] = $entry;

        $currentTime = microtime(true);
        if (
            count($this->buffer) >= self::FLUSH_BATCH_SIZE ||
            ($currentTime - $this->lastFlushTime) >= self::FLUSH_INTERVAL_SECONDS
        ) {
            $this->flush();
        }
    }

    /**
     * Flush buffered entries.
     */
    public function flush(): void
    {
        $this->lastFlushTime = microtime(true);

        if (empty($this->buffer)) {
            return;
        }

        if ($this->consecutiveFailures >= self::CIRCUIT_BREAKER_THRESHOLD) {
            $this->buffer = [];
            return;
        }

        $pending = $this->buffer;
        $this->buffer = [];

        $chunks = array_chunk($pending, self::MAX_REQUEST_ENTRIES);
        foreach ($chunks as $chunk) {
            $success = $this->sendBatch($chunk);
            if ($success) {
                $this->consecutiveFailures = 0;
            } else {
                $this->consecutiveFailures++;
                if ($this->consecutiveFailures >= self::CIRCUIT_BREAKER_THRESHOLD) {
                    $this->buffer = [];
                    break;
                }
            }
        }
    }

    /**
     * Send a batch of entries via HTTP.
     *
     * @param array<int, array<string, mixed>> $entries
     */
    protected function sendBatch(array $entries): bool
    {
        if (empty($entries)) {
            return true;
        }

        $payload = [
            'schema_version' => 1,
            'entries' => $entries,
        ];

        self::$lastSentPayload = $payload;
        self::$lastSentEntries = $entries;

        if (self::$customSender !== null) {
            try {
                $result = call_user_func(self::$customSender, $payload);
                return $result !== false;
            } catch (\Throwable) {
                return false;
            }
        }

        if (!$this->enabled || empty($this->url) || empty($this->key)) {
            return true;
        }

        $endpoint = rtrim($this->url, '/') . '/v1/logs';
        $jsonBody = json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        if ($jsonBody === false) {
            return false;
        }

        $maxRetries = 3;
        for ($attempt = 0; $attempt <= $maxRetries; $attempt++) {
            $ch = curl_init();
            if ($ch === false) {
                return false;
            }

            curl_setopt_array($ch, [
                CURLOPT_URL => $endpoint,
                CURLOPT_POST => true,
                CURLOPT_POSTFIELDS => $jsonBody,
                CURLOPT_HTTPHEADER => [
                    'Content-Type: application/json',
                    'Accept: application/json',
                    'x-api-key: ' . $this->key,
                ],
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_TIMEOUT => 5,
                CURLOPT_CONNECTTIMEOUT => 2,
                CURLOPT_NOSIGNAL => 1,
            ]);

            $response = curl_exec($ch);
            $httpCode = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
            curl_close($ch);

            if ($response !== false && $httpCode >= 200 && $httpCode < 300) {
                return true;
            }

            // Permanent rejection on 4xx (except 429 rate limit)
            if ($response !== false && $httpCode >= 400 && $httpCode < 500 && $httpCode !== 429) {
                $responseStr = is_string($response) ? $response : '';
                fwrite(STDERR, "[Logcore] Permanent rejection (HTTP {$httpCode}): {$responseStr}\n");
                return false;
            }

            // Exponential backoff retry on 5xx, 429, or network failure
            if ($attempt < $maxRetries) {
                $backoffMs = (int) (250 * (2 ** $attempt));
                usleep($backoffMs * 1000);
            }
        }

        return false;
    }

    public static function normalizeService(?string $raw): string
    {
        $service = strtolower(trim($raw ?? 'realworld-backend'));
        $service = preg_replace('/[^a-z0-9._-]/', '-', $service) ?? 'realworld-backend';
        $service = trim($service, '.-_');
        $service = substr($service, 0, 63);
        if ($service === '' || !preg_match('/^[a-z0-9]/', $service)) {
            return 'realworld-backend';
        }

        return $service;
    }

    public static function normalizeEnv(?string $raw): string
    {
        $env = strtolower(trim($raw ?? 'local'));
        $map = [
            'production' => 'prod',
            'prod' => 'prod',
            'staging' => 'staging',
            'stage' => 'staging',
            'testing' => 'test',
            'test' => 'test',
            'development' => 'dev',
            'dev' => 'dev',
            'local' => 'local',
        ];

        return $map[$env] ?? 'dev';
    }
}
