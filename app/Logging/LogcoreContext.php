<?php declare(strict_types=1);

namespace App\Logging;

class LogcoreContext
{
    /** @var array{traceId: string, spanId: string, sampled: bool}|null */
    private static ?array $currentTrace = null;

    /** @var array<string, string> */
    private static array $labels = [];

    public static function setTrace(string $traceId, string $spanId, bool $sampled = true): void
    {
        self::$currentTrace = [
            'traceId' => $traceId,
            'spanId' => $spanId,
            'sampled' => $sampled,
        ];
    }

    /**
     * @return array{traceId: string, spanId: string, sampled: bool}|null
     */
    public static function getTrace(): ?array
    {
        return self::$currentTrace;
    }

    public static function clearTrace(): void
    {
        self::$currentTrace = null;
    }

    public static function addLabel(string $key, string $value): void
    {
        if (count(self::$labels) < 32) {
            self::$labels[substr($key, 0, 1024)] = substr($value, 0, 1024);
        }
    }

    /**
     * @return array<string, string>
     */
    public static function getLabels(): array
    {
        return self::$labels;
    }

    public static function clearLabels(): void
    {
        self::$labels = [];
    }

    public static function reset(): void
    {
        self::$currentTrace = null;
        self::$labels = [];
    }
}
