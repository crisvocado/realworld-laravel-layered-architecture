<?php declare(strict_types=1);

namespace App\Logcore;

class LogcoreTraceContext
{
    /** @var array{traceId: string, spanId: ?string, sampled?: bool}|null */
    private static ?array $currentTrace = null;

    /**
     * @param array{traceId: string, spanId: ?string, sampled?: bool}|null $trace
     */
    public static function set(?array $trace): void
    {
        self::$currentTrace = $trace;
    }

    /**
     * @return array{traceId: string, spanId: ?string, sampled?: bool}|null
     */
    public static function get(): ?array
    {
        if (self::$currentTrace !== null) {
            return self::$currentTrace;
        }

        $traceparent = $_SERVER['HTTP_TRACEPARENT'] ?? null;
        if (is_string($traceparent)) {
            $parsed = self::parseTraceparent($traceparent);
            if ($parsed !== null) {
                self::$currentTrace = $parsed;
                return $parsed;
            }
        }

        return null;
    }

    public static function clear(): void
    {
        self::$currentTrace = null;
    }

    /**
     * Parse W3C traceparent header: 00-traceid-spanid-flags
     *
     * @return array{traceId: string, spanId: ?string, sampled?: bool}|null
     */
    public static function parseTraceparent(string $header): ?array
    {
        $header = trim($header);
        if (preg_match('/^([0-9a-f]{2})-([0-9a-f]{32})-([0-9a-f]{16})-([0-9a-f]{2})$/i', $header, $matches)) {
            if ($matches[2] === '00000000000000000000000000000000' || $matches[3] === '0000000000000000') {
                return null;
            }
            $flags = hexdec($matches[4]);
            return [
                'traceId' => strtolower($matches[2]),
                'spanId' => strtolower($matches[3]),
                'sampled' => ($flags & 1) === 1,
            ];
        }

        return null;
    }
}
