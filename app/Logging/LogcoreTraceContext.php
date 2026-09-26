<?php declare(strict_types=1);

namespace App\Logging;

class LogcoreTraceContext
{
    /**
     * @var array{traceId: string, spanId: string, sampled: bool}|null
     */
    private static ?array $currentTrace = null;

    /**
     * Get the current trace context if set.
     *
     * @return array{traceId: string, spanId: string, sampled: bool}|null
     */
    public static function getCurrentTrace(): ?array
    {
        return self::$currentTrace;
    }

    /**
     * Get the current trace context or generate a new one.
     *
     * @return array{traceId: string, spanId: string, sampled: bool}
     */
    public static function getOrCreateTrace(): array
    {
        if (self::$currentTrace === null) {
            self::$currentTrace = [
                'traceId' => bin2hex(random_bytes(16)),
                'spanId' => bin2hex(random_bytes(8)),
                'sampled' => true,
            ];
        }

        return self::$currentTrace;
    }

    /**
     * Explicitly set the trace context.
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
     * Set the trace context from a W3C traceparent header.
     * Format: version-trace_id-parent_id-trace_flags
     * Example: 00-4bf92f3577b34da6a3ce929d0e0e4736-00f067aa0ba902b7-01
     */
    public static function setTraceFromHeader(?string $header): void
    {
        if ($header !== null && preg_match('/^([0-9a-f]{2})-([0-9a-f]{32})-([0-9a-f]{16})-([0-9a-f]{2})$/i', trim($header), $matches)) {
            $traceId = strtolower($matches[2]);
            $spanId = strtolower($matches[3]);
            $flags = hexdec($matches[4]);

            if ($traceId !== '00000000000000000000000000000000' && $spanId !== '0000000000000000') {
                self::$currentTrace = [
                    'traceId' => $traceId,
                    'spanId' => $spanId,
                    'sampled' => ($flags & 1) === 1,
                ];
                return;
            }
        }

        self::$currentTrace = [
            'traceId' => bin2hex(random_bytes(16)),
            'spanId' => bin2hex(random_bytes(8)),
            'sampled' => true,
        ];
    }

    /**
     * Generate a W3C traceparent header string from the current trace context.
     */
    public static function generateTraceparentHeader(): string
    {
        $trace = self::getOrCreateTrace();
        $flags = $trace['sampled'] ? '01' : '00';

        return sprintf('00-%s-%s-%s', $trace['traceId'], $trace['spanId'], $flags);
    }

    /**
     * Reset the trace context (useful for testing or worker loop recycling).
     */
    public static function reset(): void
    {
        self::$currentTrace = null;
    }
}
