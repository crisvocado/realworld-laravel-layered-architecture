<?php declare(strict_types=1);

namespace App\Logging;

class LogcoreTraceContext
{
    /** @var array{traceId: string, spanId?: string|null, sampled?: bool}|null */
    private static ?array $currentTrace = null;

    /**
     * @return array{traceId: string, spanId?: string|null, sampled?: bool}|null
     */
    public static function get(): ?array
    {
        return self::$currentTrace;
    }

    /**
     * @param array{traceId: string, spanId?: string|null, sampled?: bool}|null $trace
     */
    public static function set(?array $trace): void
    {
        self::$currentTrace = $trace;
    }

    public static function reset(): void
    {
        self::$currentTrace = null;
    }

    /**
     * Parse W3C traceparent header: 00-{trace_id_32_hex}-{parent_id_16_hex}-{flags_2_hex}
     *
     * @return array{traceId: string, spanId?: string|null, sampled?: bool}|null
     */
    public static function fromTraceparent(?string $header): ?array
    {
        if ($header === null || $header === '') {
            return null;
        }

        $header = trim($header);
        if (!preg_match('/^([0-9a-f]{2})-([0-9a-f]{32})-([0-9a-f]{16})-([0-9a-f]{2})$/i', $header, $matches)) {
            return null;
        }

        $traceId = strtolower($matches[2]);
        $spanId = strtolower($matches[3]);
        $flags = (int) hexdec($matches[4]);

        if ($traceId === '00000000000000000000000000000000') {
            return null;
        }

        $result = [
            'traceId' => $traceId,
            'sampled' => ($flags & 0x01) === 1,
        ];

        if ($spanId !== '0000000000000000') {
            $result['spanId'] = $spanId;
        }

        return $result;
    }
}
