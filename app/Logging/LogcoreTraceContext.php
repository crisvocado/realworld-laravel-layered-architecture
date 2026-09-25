<?php declare(strict_types=1);

namespace App\Logging;

class LogcoreTraceContext
{
    /**
     * @var array{traceId: string, spanId: string, sampled: bool}|null
     */
    private static ?array $currentTrace = null;

    /**
     * Set explicit trace context.
     */
    public static function setTrace(string $traceId, string $spanId, bool $sampled = true): void
    {
        self::$currentTrace = [
            'traceId' => strtolower($traceId),
            'spanId' => strtolower($spanId),
            'sampled' => $sampled,
        ];
    }

    /**
     * Parse and set trace context from a W3C traceparent header value.
     */
    public static function setTraceparent(?string $traceparent): void
    {
        if ($traceparent !== null && preg_match('/^00-([0-9a-fA-F]{32})-([0-9a-fA-F]{16})-([0-9a-fA-F]{2})$/', trim($traceparent), $matches)) {
            $flags = (int) hexdec($matches[3]);
            self::$currentTrace = [
                'traceId' => strtolower($matches[1]),
                'spanId' => strtolower($matches[2]),
                'sampled' => ($flags & 1) === 1,
            ];
            return;
        }

        self::generateTrace();
    }

    /**
     * Get or lazily initialize the current trace context.
     *
     * @return array{traceId: string, spanId: string, sampled: bool}
     */
    public static function getTrace(): array
    {
        if (self::$currentTrace === null) {
            self::generateTrace();
        }

        return self::$currentTrace;
    }

    /**
     * Generate a new trace context.
     *
     * @return array{traceId: string, spanId: string, sampled: bool}
     */
    public static function generateTrace(): array
    {
        self::$currentTrace = [
            'traceId' => bin2hex(random_bytes(16)),
            'spanId' => bin2hex(random_bytes(8)),
            'sampled' => true,
        ];

        return self::$currentTrace;
    }

    /**
     * Generate the W3C traceparent string for downstream propagation.
     */
    public static function generateTraceparent(): string
    {
        $trace = self::getTrace();
        $flags = $trace['sampled'] ? '01' : '00';
        return sprintf('00-%s-%s-%s', $trace['traceId'], $trace['spanId'], $flags);
    }

    /**
     * Clear trace context.
     */
    public static function clear(): void
    {
        self::$currentTrace = null;
    }
}
