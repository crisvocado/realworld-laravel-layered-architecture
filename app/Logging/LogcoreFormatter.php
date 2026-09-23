<?php declare(strict_types=1);

namespace App\Logging;

use Monolog\LogRecord;
use Throwable;

class LogcoreFormatter
{
    private const MAX_MESSAGE_LENGTH = 65536;
    private const MAX_LABELS_COUNT = 32;
    private const MAX_LABEL_STRING_LENGTH = 1024;
    private const MAX_STACK_FRAMES = 50;

    /**
     * Format a Monolog LogRecord or record array into a Logcore structured entry.
     *
     * @param LogRecord|array<string, mixed> $record
     * @return array<string, mixed>|null
     */
    public static function formatRecord(LogRecord|array $record): ?array
    {
        $levelName = self::extractLevelName($record);
        $severity = self::mapSeverity($levelName);

        // Only process ERROR and higher severities
        if (!self::isErrorOrHigher($severity)) {
            return null;
        }

        $timestamp = self::formatTimestamp($record);
        $message = self::extractMessage($record);
        $service = self::resolveService();
        $env = self::resolveEnv();
        $context = self::extractContext($record);
        $labels = self::extractLabels($record);
        $trace = LogcoreContext::getTrace();
        $error = self::extractError($record, $message);

        $insertId = self::computeInsertId($timestamp, $service, $severity, $message, $context);

        $entry = [
            'timestamp' => $timestamp,
            'severity' => $severity,
            'message' => $message,
            'service' => $service,
            'env' => $env,
            'insert_id' => $insertId,
        ];

        if ($trace !== null) {
            $entry['trace'] = $trace;
        }

        if ($error !== null) {
            $entry['error'] = $error;
        }

        if (!empty($labels)) {
            $entry['labels'] = $labels;
        }

        if (!empty($context)) {
            $entry['context'] = $context;
        }

        return $entry;
    }

    /**
     * Compute a deterministic 32-hex character insert_id.
     * sha256(timestamp | service | severity | message | canonical_json(context))[:32]
     *
     * @param array<string, mixed> $context
     */
    public static function computeInsertId(
        string $timestamp,
        string $service,
        string $severity,
        string $message,
        array $context
    ): string {
        $canonicalJson = !empty($context)
            ? (json_encode($context, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) ?: '{}')
            : '{}';

        $raw = sprintf('%s|%s|%s|%s|%s', $timestamp, $service, $severity, $message, $canonicalJson);

        return substr(hash('sha256', $raw), 0, 32);
    }

    /**
     * Parse a Throwable into an error payload matching Logcore schema.
     *
     * @return array{type: string, message: string, stack: list<array{function: string, file: string, line: int, column: int, inApp: bool}>}
     */
    public static function parseThrowable(Throwable $throwable): array
    {
        // Follow exception chain to its root cause
        $chain = [];
        $current = $throwable;
        while ($current !== null) {
            $chain[] = $current;
            $current = $current->getPrevious();
        }

        // The root cause is where the actual problem originated
        $rootCause = end($chain);

        $frames = [];

        // Innermost frame: where root exception was thrown
        $rootFile = $rootCause->getFile();
        $rootLine = $rootCause->getLine();
        if (!empty($rootFile)) {
            $rootTrace = $rootCause->getTrace();
            $rootFn = '{main}';
            if (!empty($rootTrace) && isset($rootTrace[0]['function'])) {
                $rootFn = (string) $rootTrace[0]['function'];
                if (isset($rootTrace[0]['class'])) {
                    $rootFn = $rootTrace[0]['class'] . ($rootTrace[0]['type'] ?? '::') . $rootFn;
                }
            }

            $frames[] = [
                'function' => $rootFn,
                'file' => $rootFile,
                'line' => $rootLine,
                'column' => 0,
                'inApp' => self::isInApp($rootFile),
            ];
        }

        // Add trace frames starting from root cause upwards
        foreach ($chain as $ex) {
            foreach ($ex->getTrace() as $traceFrame) {
                $file = (string) ($traceFrame['file'] ?? '');
                $line = (int) ($traceFrame['line'] ?? 0);
                $fn = (string) ($traceFrame['function'] ?? '<anon>');
                if (isset($traceFrame['class'])) {
                    $fn = $traceFrame['class'] . ($traceFrame['type'] ?? '::') . $fn;
                }

                $frames[] = [
                    'function' => $fn,
                    'file' => $file,
                    'line' => $line,
                    'column' => 0,
                    'inApp' => !empty($file) && self::isInApp($file),
                ];

                if (count($frames) >= self::MAX_STACK_FRAMES) {
                    break 2;
                }
            }
        }

        if (count($frames) > self::MAX_STACK_FRAMES) {
            $frames = array_slice($frames, 0, self::MAX_STACK_FRAMES);
        }

        return [
            'type' => get_class($throwable),
            'message' => $throwable->getMessage(),
            'stack' => $frames,
        ];
    }

    /**
     * Determine if a file path belongs to application code (not third-party vendor code).
     */
    public static function isInApp(string $file): bool
    {
        $normalized = str_replace('\\', '/', $file);
        if (str_contains($normalized, '/vendor/')) {
            return false;
        }
        if (str_contains($normalized, '/storage/framework/')) {
            return false;
        }

        return true;
    }

    /**
     * Format timestamp as ISO-8601 UTC with milliseconds (e.g. 2026-08-17T18:30:00.000Z).
     *
     * @param LogRecord|array<string, mixed> $record
     */
    private static function formatTimestamp(LogRecord|array $record): string
    {
        $dt = null;
        if ($record instanceof LogRecord) {
            $dt = $record->datetime;
        } elseif (isset($record['datetime']) && $record['datetime'] instanceof \DateTimeInterface) {
            $dt = $record['datetime'];
        }

        if (!$dt instanceof \DateTimeInterface) {
            $dt = new \DateTimeImmutable('now', new \DateTimeZone('UTC'));
        }

        $utc = $dt->setTimezone(new \DateTimeZone('UTC'));

        return $utc->format('Y-m-d\TH:i:s.v\Z');
    }

    /**
     * Extract and clamp message string.
     *
     * @param LogRecord|array<string, mixed> $record
     */
    private static function extractMessage(LogRecord|array $record): string
    {
        $msg = $record instanceof LogRecord ? (string) $record->message : (string) ($record['message'] ?? '');
        if (mb_strlen($msg, 'UTF-8') > self::MAX_MESSAGE_LENGTH) {
            $msg = mb_substr($msg, 0, self::MAX_MESSAGE_LENGTH, 'UTF-8');
        }

        return $msg;
    }

    /**
     * Resolve service name matching ^[a-z0-9][a-z0-9._-]{0,62}$.
     */
    private static function resolveService(): string
    {
        $raw = env('LOGCORE_SERVICE') ?: (function_exists('config') ? config('app.name', 'laravel') : 'laravel');
        $sanitized = strtolower(trim((string) $raw));
        $sanitized = (string) preg_replace('/[^a-z0-9._-]/', '-', $sanitized);
        $sanitized = (string) preg_replace('/^[._-]+/', '', $sanitized);
        $sanitized = substr($sanitized, 0, 63);

        return !empty($sanitized) ? $sanitized : 'laravel';
    }

    /**
     * Resolve environment name enum (prod|staging|dev|test|local).
     */
    private static function resolveEnv(): string
    {
        $raw = env('LOGCORE_ENV') ?: (function_exists('config') ? config('app.env', 'local') : 'local');
        $normalized = strtolower(trim((string) $raw));

        $envMap = [
            'production' => 'prod',
            'prod' => 'prod',
            'staging' => 'staging',
            'stage' => 'staging',
            'development' => 'dev',
            'dev' => 'dev',
            'testing' => 'test',
            'test' => 'test',
            'local' => 'local',
        ];

        return $envMap[$normalized] ?? 'local';
    }

    /**
     * Map Monolog level name to uppercase string.
     */
    private static function mapSeverity(string $levelName): string
    {
        $upper = strtoupper(trim($levelName));

        return match ($upper) {
            'EMERGENCY' => 'EMERGENCY',
            'ALERT' => 'ALERT',
            'CRITICAL' => 'CRITICAL',
            default => 'ERROR',
        };
    }

    private static function isErrorOrHigher(string $severity): bool
    {
        return in_array($severity, ['ERROR', 'CRITICAL', 'ALERT', 'EMERGENCY'], true);
    }

    /**
     * Extract level name from record.
     *
     * @param LogRecord|array<string, mixed> $record
     */
    private static function extractLevelName(LogRecord|array $record): string
    {
        if ($record instanceof LogRecord) {
            return $record->level->name;
        }

        if (isset($record['level_name'])) {
            return (string) $record['level_name'];
        }

        if (isset($record['level'])) {
            if (is_object($record['level']) && isset($record['level']->name)) {
                return (string) $record['level']->name;
            }

            return (string) $record['level'];
        }

        return 'ERROR';
    }

    /**
     * Extract sanitized context data.
     *
     * @param LogRecord|array<string, mixed> $record
     * @return array<string, mixed>
     */
    private static function extractContext(LogRecord|array $record): array
    {
        $raw = $record instanceof LogRecord ? $record->context : ($record['context'] ?? []);
        $merged = array_merge(LogcoreContext::getContext(), is_array($raw) ? $raw : []);

        // Filter out exception objects and redact sensitive information
        $clean = [];
        foreach ($merged as $key => $val) {
            if ($key === 'exception' || $key === 'error') {
                continue;
            }
            if (self::isSensitiveKey((string) $key)) {
                $clean[$key] = '[REDACTED]';
                continue;
            }
            $clean[$key] = self::sanitizeValue($val);
        }

        return $clean;
    }

    /**
     * Extract labels map (string -> string, <= 32 items, <= 1024 chars).
     *
     * @param LogRecord|array<string, mixed> $record
     * @return array<string, string>
     */
    private static function extractLabels(LogRecord|array $record): array
    {
        $labels = LogcoreContext::getLabels();
        $extra = $record instanceof LogRecord ? $record->extra : ($record['extra'] ?? []);

        if (is_array($extra)) {
            foreach ($extra as $k => $v) {
                if (count($labels) >= self::MAX_LABELS_COUNT) {
                    break;
                }
                if (is_scalar($v) && !self::isSensitiveKey((string) $k)) {
                    $key = substr((string) $k, 0, self::MAX_LABEL_STRING_LENGTH);
                    $val = substr((string) $v, 0, self::MAX_LABEL_STRING_LENGTH);
                    $labels[$key] = $val;
                }
            }
        }

        return $labels;
    }

    /**
     * Extract error object from exception or backtrace.
     *
     * @param LogRecord|array<string, mixed> $record
     * @return array<string, mixed>|null
     */
    private static function extractError(LogRecord|array $record, string $message): ?array
    {
        $context = $record instanceof LogRecord ? $record->context : ($record['context'] ?? []);

        if (is_array($context)) {
            if (isset($context['exception']) && $context['exception'] instanceof Throwable) {
                return self::parseThrowable($context['exception']);
            }
            if (isset($context['error']) && $context['error'] instanceof Throwable) {
                return self::parseThrowable($context['error']);
            }
        }

        // Caller backtrace capture for non-exception ERROR records
        $backtrace = debug_backtrace(DEBUG_BACKTRACE_IGNORE_ARGS, 50);
        $callerFrame = null;

        foreach ($backtrace as $frame) {
            $file = (string) ($frame['file'] ?? '');
            if (empty($file)) {
                continue;
            }
            $norm = str_replace('\\', '/', $file);
            // Skip framework logger and logcore internal classes
            if (
                str_contains($norm, '/app/Logging/') ||
                str_contains($norm, '/vendor/monolog/') ||
                str_contains($norm, '/monolog/') ||
                str_contains($norm, '/vendor/laravel/framework/src/Illuminate/Log/') ||
                str_contains($norm, '/vendor/laravel/framework/src/Illuminate/Support/Facades/') ||
                str_contains($norm, '/laravel/framework/src/Illuminate/Log/')
            ) {
                continue;
            }
            $callerFrame = $frame;
            break;
        }

        if ($callerFrame !== null) {
            $file = (string) ($callerFrame['file'] ?? '');
            $line = (int) ($callerFrame['line'] ?? 0);
            $fn = (string) ($callerFrame['function'] ?? '<anon>');
            if (isset($callerFrame['class'])) {
                $fn = $callerFrame['class'] . ($callerFrame['type'] ?? '::') . $fn;
            }

            return [
                'type' => 'Error',
                'message' => $message,
                'stack' => [
                    [
                        'function' => $fn,
                        'file' => $file,
                        'line' => $line,
                        'column' => 0,
                        'inApp' => !empty($file) && self::isInApp($file),
                    ],
                ],
            ];
        }

        return null;
    }

    private static function isSensitiveKey(string $key): bool
    {
        return (bool) preg_match('/(password|secret|token|api_key|apikey|auth|authorization|bearer|cookie)/i', $key);
    }

    private static function sanitizeValue(mixed $value): mixed
    {
        if ($value instanceof Throwable) {
            return sprintf('%s: %s', get_class($value), $value->getMessage());
        }

        if (is_object($value)) {
            if (method_exists($value, '__toString')) {
                return (string) $value;
            }
            if (method_exists($value, 'toArray')) {
                return self::sanitizeValue($value->toArray());
            }

            return get_class($value);
        }

        if (is_array($value)) {
            $sanitized = [];
            foreach ($value as $k => $v) {
                if (self::isSensitiveKey((string) $k)) {
                    $sanitized[$k] = '[REDACTED]';
                } else {
                    $sanitized[$k] = self::sanitizeValue($v);
                }
            }

            return $sanitized;
        }

        if (is_resource($value)) {
            return get_resource_type($value);
        }

        return $value;
    }
}
