<?php declare(strict_types=1);

namespace App\Logging;

use Monolog\LogRecord;

class LogcoreFormatter
{
    /**
     * Format a Monolog LogRecord into the Logcore structured entry shape.
     *
     * @return array<string, mixed>
     */
    public function formatRecord(LogRecord $record): array
    {
        $timestamp = $record->datetime->setTimezone(new \DateTimeZone('UTC'))->format('Y-m-d\TH:i:s.v\Z');
        $severity = strtoupper($record->level->name);
        $message = substr((string) $record->message, 0, 65536);

        $service = self::sanitizeService((string) env('LOGCORE_SERVICE', self::getConfig('app.name', 'laravel')));
        $env = self::sanitizeEnv((string) env('LOGCORE_ENV', self::getConfig('app.env', 'local')));

        $cleanContext = $this->sanitizeContext($record->context);

        $canonicalContext = self::canonicalJson($cleanContext);
        $insertIdRaw = sprintf('%s|%s|%s|%s|%s', $timestamp, $service, $severity, $message, $canonicalContext);
        $insertId = substr(hash('sha256', $insertIdRaw), 0, 32);

        $trace = LogcoreTraceContext::getTrace();

        $entry = [
            'timestamp' => $timestamp,
            'severity' => $severity,
            'message' => $message,
            'service' => $service,
            'env' => $env,
            'insert_id' => $insertId,
            'trace' => $trace,
        ];

        // Exception / Error handling
        $exception = $this->findException($record->context);
        if ($exception !== null) {
            $entry['error'] = self::parseException($exception);
        }

        // Labels (max 32 items, max 1024 chars each)
        $labels = $this->extractLabels($record);
        if (!empty($labels)) {
            $entry['labels'] = $labels;
        }

        // Context
        if (!empty($cleanContext)) {
            $entry['context'] = $cleanContext;
        }

        $sourceProject = env('LOGCORE_SOURCE_PROJECT');
        if (is_string($sourceProject) && preg_match('/^[a-z][a-z0-9-]{4,28}[a-z0-9]$/', trim($sourceProject))) {
            $entry['source_project'] = trim($sourceProject);
        }

        return $entry;
    }

    /**
     * Sanitize service name to match pattern ^[a-z0-9][a-z0-9._-]{0,62}$
     */
    public static function sanitizeService(?string $service): string
    {
        if ($service === null || trim($service) === '') {
            $service = (string) self::getConfig('app.name', 'laravel');
        }

        $service = strtolower(trim($service));
        $service = (string) preg_replace('/[^a-z0-9._-]/', '-', $service);
        $service = trim($service, '._-');

        if ($service === '') {
            $service = 'laravel';
        }

        return substr($service, 0, 63);
    }

    /**
     * Sanitize env to one of: prod, staging, dev, test, local.
     */
    public static function sanitizeEnv(?string $env): string
    {
        if ($env === null || trim($env) === '') {
            $env = (string) self::getConfig('app.env', 'local');
        }

        $env = strtolower(trim($env));

        return match ($env) {
            'production', 'prod' => 'prod',
            'staging', 'stage' => 'staging',
            'development', 'dev' => 'dev',
            'testing', 'test', 'phpunit' => 'test',
            'local' => 'local',
            default => in_array($env, ['prod', 'staging', 'dev', 'test', 'local'], true) ? $env : 'local',
        };
    }

    /**
     * Safe base path reader.
     */
    public static function getBasePath(): string
    {
        try {
            if (function_exists('base_path')) {
                $container = \Illuminate\Container\Container::getInstance();
                if ($container instanceof \Illuminate\Foundation\Application) {
                    return $container->basePath();
                }
            }
        } catch (\Throwable) {
        }

        return (string) getcwd();
    }

    /**
     * Safe config reader.
     */
    public static function getConfig(string $key, mixed $default = null): mixed
    {
        try {
            if (function_exists('config')) {
                $container = \Illuminate\Container\Container::getInstance();
                if ($container !== null && $container->bound('config')) {
                    return config($key, $default);
                }
            }
        } catch (\Throwable) {
        }

        return $default;
    }

    /**
     * Canonical JSON string with sorted keys recursively.
     */
    public static function canonicalJson(mixed $data): string
    {
        if (!is_array($data)) {
            return (string) json_encode($data, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        }

        $sorted = self::sortKeysRecursively($data);
        $json = json_encode($sorted, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);

        return $json !== false ? $json : '{}';
    }

    /**
     * @param array<mixed> $array
     * @return array<mixed>
     */
    private static function sortKeysRecursively(array $array): array
    {
        $isAssoc = array_keys($array) !== range(0, count($array) - 1);
        if ($isAssoc) {
            ksort($array);
        }

        foreach ($array as $key => $value) {
            if (is_array($value)) {
                $array[$key] = self::sortKeysRecursively($value);
            }
        }

        return $array;
    }

    /**
     * Parse exception and follow cause chain to root.
     *
     * @return array{type: string, message: string, stack: array<int, array{function: string, file: string, line: int, column: int, inApp: bool}>}
     */
    public static function parseException(\Throwable $e): array
    {
        $chain = [];
        $curr = $e;
        while ($curr !== null) {
            $chain[] = $curr;
            $curr = $curr->getPrevious();
        }
        // Innermost (root cause) first
        $chain = array_reverse($chain);

        $frames = [];
        $basePath = self::getBasePath();

        foreach ($chain as $exc) {
            $throwFile = $exc->getFile();
            $throwLine = $exc->getLine();
            $throwTrace = $exc->getTrace();

            $throwFunction = '<throw>';
            if (!empty($throwTrace)) {
                $first = $throwTrace[0];
                $throwFunction = $first['function'];
                if (isset($first['class'])) {
                    $throwFunction = $first['class'] . ($first['type'] ?? '::') . $first['function'];
                }
            }

            $frames[] = [
                'function' => $throwFunction,
                'file' => $throwFile,
                'line' => $throwLine > 0 ? $throwLine : 1,
                'column' => 0,
                'inApp' => self::isInApp($throwFile, $basePath),
            ];

            foreach ($throwTrace as $frame) {
                $file = $frame['file'] ?? '<internal>';
                $line = isset($frame['line']) ? (int) $frame['line'] : 1;
                $function = $frame['function'];
                if (isset($frame['class'])) {
                    $function = $frame['class'] . ($frame['type'] ?? '::') . $function;
                }

                $frames[] = [
                    'function' => $function,
                    'file' => $file,
                    'line' => $line > 0 ? $line : 1,
                    'column' => 0,
                    'inApp' => self::isInApp($file, $basePath),
                ];

                if (count($frames) >= 50) {
                    break 2;
                }
            }
        }

        $frames = array_slice($frames, 0, 50);

        return [
            'type' => (new \ReflectionClass($e))->getShortName(),
            'message' => $e->getMessage(),
            'stack' => $frames,
        ];
    }

    private static function isInApp(string $file, string|false $basePath): bool
    {
        if ($file === '' || str_starts_with($file, '<') || str_contains($file, 'eval()') || str_contains($file, '/vendor/')) {
            return false;
        }

        if ($basePath !== false && $basePath !== '' && str_starts_with($file, $basePath)) {
            return true;
        }

        return !str_contains($file, '/vendor/');
    }

    /**
     * @param array<string, mixed> $context
     */
    private function findException(array $context): ?\Throwable
    {
        if (isset($context['exception']) && $context['exception'] instanceof \Throwable) {
            return $context['exception'];
        }

        foreach ($context as $value) {
            if ($value instanceof \Throwable) {
                return $value;
            }
        }

        return null;
    }

    /**
     * @param array<string, mixed> $context
     * @return array<string, mixed>
     */
    private function sanitizeContext(array $context): array
    {
        $clean = [];
        foreach ($context as $key => $value) {
            if ($key === 'exception' && $value instanceof \Throwable) {
                continue;
            }
            $clean[(string) $key] = $this->sanitizeValue($value);
        }

        return $clean;
    }

    private function sanitizeValue(mixed $value): mixed
    {
        if ($value === null || is_scalar($value)) {
            return $value;
        }

        if (is_array($value)) {
            $res = [];
            foreach ($value as $k => $v) {
                $res[(string) $k] = $this->sanitizeValue($v);
            }
            return $res;
        }

        if ($value instanceof \DateTimeInterface) {
            return $value->format(\DateTimeInterface::ATOM);
        }

        if ($value instanceof \Throwable) {
            return [
                'class' => get_class($value),
                'message' => $value->getMessage(),
            ];
        }

        if (is_object($value)) {
            if (method_exists($value, '__toString')) {
                return (string) $value;
            }
            if (method_exists($value, 'toArray')) {
                return $this->sanitizeValue($value->toArray());
            }
            return get_class($value);
        }

        return gettype($value);
    }

    /**
     * @return array<string, string>
     */
    private function extractLabels(LogRecord $record): array
    {
        $labels = [];

        if (!empty($record->channel)) {
            $labels['channel'] = substr((string) $record->channel, 0, 1024);
        }

        if (!empty($record->extra)) {
            foreach ($record->extra as $k => $v) {
                if (count($labels) >= 32) {
                    break;
                }
                if (is_scalar($v)) {
                    $labels[(string) $k] = substr((string) $v, 0, 1024);
                }
            }
        }

        return $labels;
    }
}
