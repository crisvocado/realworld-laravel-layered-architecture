<?php declare(strict_types=1);

namespace App\Logging;

use Monolog\LogRecord;
use Throwable;

class LogcoreFormatter
{
    /**
     * Format a Monolog record into a Logcore entry object.
     *
     * @return array<string, mixed>
     */
    public function formatRecord(LogRecord $record): array
    {
        $timestamp = $record->datetime->setTimezone(new \DateTimeZone('UTC'))->format('Y-m-d\TH:i:s.v\Z');
        $severity = strtoupper($record->level->getName());

        $message = (string) $record->message;
        if ($message === '') {
            $message = '(empty message)';
        } elseif (strlen($message) > 65536) {
            $message = substr($message, 0, 65536);
        }

        $service = $this->resolveService();
        $env = $this->resolveEnv();

        $context = $record->context;
        $labels = null;
        $error = null;

        if (isset($context['labels']) && is_array($context['labels'])) {
            $labels = $this->sanitizeLabels($context['labels']);
            unset($context['labels']);
        }

        if (isset($context['exception']) && $context['exception'] instanceof Throwable) {
            $error = $this->formatError($context['exception']);
            unset($context['exception']);
        } elseif (isset($context['error']) && $context['error'] instanceof Throwable) {
            $error = $this->formatError($context['error']);
            unset($context['error']);
        }

        $cleanContext = $this->cleanContext($context);

        $hashInput = $timestamp . '|' . $service . '|' . $severity . '|' . $message . '|' . (!empty($cleanContext) ? json_encode($cleanContext, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) : '');
        $insertId = substr(hash('sha256', $hashInput), 0, 32);

        $entry = [
            'timestamp' => $timestamp,
            'severity' => $severity,
            'message' => $message,
            'service' => $service,
            'env' => $env,
            'insert_id' => $insertId,
        ];

        $trace = LogcoreTraceContext::get();
        if ($trace !== null && !empty($trace['traceId'])) {
            $traceEntry = ['traceId' => $trace['traceId']];
            if (!empty($trace['spanId'])) {
                $traceEntry['spanId'] = $trace['spanId'];
            }
            if (isset($trace['sampled'])) {
                $traceEntry['sampled'] = (bool) $trace['sampled'];
            }
            $entry['trace'] = $traceEntry;
        }

        if ($error !== null) {
            $entry['error'] = $error;
        }

        if (!empty($labels)) {
            $entry['labels'] = $labels;
        }

        if (!empty($cleanContext)) {
            $entry['context'] = $cleanContext;
        }

        return $entry;
    }

    protected function resolveService(): string
    {
        $service = env('LOGCORE_SERVICE');
        if (is_string($service) && $service !== '') {
            $service = strtolower((string) preg_replace('/[^a-zA-Z0-9._-]/', '-', $service));
            $service = trim($service, '-_.');
            if ($service !== '' && preg_match('/^[a-z0-9][a-z0-9._-]{0,62}$/', $service)) {
                return $service;
            }
        }

        $appName = config('app.name', 'laravel');
        if (is_string($appName) && $appName !== '') {
            $appName = strtolower((string) preg_replace('/[^a-zA-Z0-9._-]/', '-', $appName));
            $appName = trim($appName, '-_.');
            if ($appName !== '' && preg_match('/^[a-z0-9][a-z0-9._-]{0,62}$/', $appName)) {
                return substr($appName, 0, 63);
            }
        }

        return 'laravel';
    }

    protected function resolveEnv(): string
    {
        $env = env('LOGCORE_ENV', env('APP_ENV', 'local'));
        $env = is_string($env) ? strtolower(trim($env)) : 'local';

        $map = [
            'production' => 'prod',
            'testing' => 'test',
            'development' => 'dev',
        ];

        $env = $map[$env] ?? $env;

        $allowed = ['prod', 'staging', 'dev', 'test', 'local'];
        if (!in_array($env, $allowed, true)) {
            return 'dev';
        }

        return $env;
    }

    /**
     * @param array<mixed, mixed> $labels
     * @return array<string, string>
     */
    protected function sanitizeLabels(array $labels): array
    {
        $sanitized = [];
        $count = 0;
        foreach ($labels as $k => $v) {
            if ($count >= 32) {
                break;
            }
            $key = (string) $k;
            if (preg_match('/^[a-zA-Z0-9_-]{1,64}$/', $key)) {
                $val = is_scalar($v) ? (string) $v : json_encode($v);
                $sanitized[$key] = substr((string) $val, 0, 1024);
                $count++;
            }
        }
        return $sanitized;
    }

    /**
     * @param Throwable $exception
     * @return array<string, mixed>
     */
    protected function formatError(Throwable $exception): array
    {
        $root = $exception;
        while ($root->getPrevious() !== null) {
            $root = $root->getPrevious();
        }

        $stack = [];

        // Innermost frame: throw site of root exception
        $throwFile = $root->getFile();
        $throwLine = $root->getLine();
        $throwTrace = $root->getTrace();
        $throwFunc = isset($throwTrace[0]['function'])
            ? (isset($throwTrace[0]['class']) ? $throwTrace[0]['class'] . ($throwTrace[0]['type'] ?? '::') : '') . $throwTrace[0]['function']
            : '{main}';

        $stack[] = [
            'function' => substr($throwFunc, 0, 256),
            'file' => $throwFile !== '' ? substr($throwFile, 0, 1024) : null,
            'line' => $throwLine > 0 ? $throwLine : null,
            'inApp' => $throwFile !== '' && !str_contains($throwFile, DIRECTORY_SEPARATOR . 'vendor' . DIRECTORY_SEPARATOR),
        ];

        foreach ($throwTrace as $frame) {
            if (count($stack) >= 50) {
                break;
            }
            $func = (isset($frame['class']) ? $frame['class'] . ($frame['type'] ?? '::') : '') . ($frame['function'] ?? '<unknown>');
            $file = isset($frame['file']) && is_string($frame['file']) ? $frame['file'] : null;
            $line = isset($frame['line']) && is_int($frame['line']) ? $frame['line'] : null;
            $inApp = $file !== null && !str_contains($file, DIRECTORY_SEPARATOR . 'vendor' . DIRECTORY_SEPARATOR);

            $stack[] = [
                'function' => substr($func, 0, 256),
                'file' => $file !== null ? substr($file, 0, 1024) : null,
                'line' => $line !== null && $line > 0 ? $line : null,
                'inApp' => $inApp,
            ];
        }

        $type = get_class($exception);
        $message = $exception->getMessage();
        if ($message === '') {
            $message = get_class($exception);
        }

        return [
            'type' => substr($type, 0, 256),
            'message' => substr($message, 0, 65536),
            'stack' => array_slice($stack, 0, 50),
        ];
    }

    /**
     * @param array<mixed, mixed> $context
     * @return array<string, mixed>
     */
    protected function cleanContext(array $context): array
    {
        $clean = [];
        foreach ($context as $key => $value) {
            $cleanKey = (string) $key;
            if ($value instanceof Throwable) {
                continue;
            }
            if (is_resource($value)) {
                $clean[$cleanKey] = get_resource_type($value);
            } elseif (is_object($value)) {
                if (method_exists($value, '__toString')) {
                    $clean[$cleanKey] = (string) $value;
                } elseif (method_exists($value, 'toArray')) {
                    $clean[$cleanKey] = $this->cleanContext((array) $value->toArray());
                } else {
                    $clean[$cleanKey] = get_class($value);
                }
            } elseif (is_array($value)) {
                $clean[$cleanKey] = $this->cleanContext($value);
            } else {
                $clean[$cleanKey] = $value;
            }
        }
        return $clean;
    }
}
