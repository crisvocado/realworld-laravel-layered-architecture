<?php declare(strict_types=1);

namespace App\Logging;

class LogcoreContext
{
    /**
     * @var array{traceId: string, spanId: string, sampled: bool}|null
     */
    private static ?array $trace = null;

    /**
     * @var array<string, string>
     */
    private static array $labels = [];

    /**
     * @var array<string, mixed>
     */
    private static array $context = [];

    /**
     * Set the active W3C trace context.
     *
     * @param array{traceId: string, spanId: string, sampled: bool}|null $trace
     */
    public static function setTrace(?array $trace): void
    {
        self::$trace = $trace;
    }

    /**
     * Get the active W3C trace context.
     *
     * @return array{traceId: string, spanId: string, sampled: bool}|null
     */
    public static function getTrace(): ?array
    {
        return self::$trace;
    }

    /**
     * Set a custom label (clamped to 32 labels, max 1024 chars per key/value).
     */
    public static function setLabel(string $key, string $value): void
    {
        if (count(self::$labels) < 32) {
            $clampedKey = substr($key, 0, 1024);
            $clampedVal = substr($value, 0, 1024);
            self::$labels[$clampedKey] = $clampedVal;
        }
    }

    /**
     * Get all active labels.
     *
     * @return array<string, string>
     */
    public static function getLabels(): array
    {
        return self::$labels;
    }

    /**
     * Add context data.
     */
    public static function addContext(string $key, mixed $value): void
    {
        self::$context[$key] = $value;
    }

    /**
     * Get all active context data.
     *
     * @return array<string, mixed>
     */
    public static function getContext(): array
    {
        return self::$context;
    }

    /**
     * Clear all request-scoped context.
     */
    public static function clear(): void
    {
        self::$trace = null;
        self::$labels = [];
        self::$context = [];
    }
}
