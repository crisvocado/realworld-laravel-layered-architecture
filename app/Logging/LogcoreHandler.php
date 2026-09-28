<?php declare(strict_types=1);

namespace App\Logging;

use Monolog\Handler\AbstractProcessingHandler;
use Monolog\Level;
use Monolog\LogRecord;
use ReflectionClass;
use Throwable;

class LogcoreHandler extends AbstractProcessingHandler
{
    protected LogcoreTransport $transport;
    protected string $service;
    protected string $env;
    protected ?string $basePath;

    public function __construct(
        int|string|Level $level = Level::Error,
        bool $bubble = true,
        mixed $transport = null
    ) {
        parent::__construct($level, $bubble);
        $this->transport = $transport instanceof LogcoreTransport ? $transport : LogcoreTransport::getInstance();
        $this->basePath = $this->resolveBasePath();
        $this->initServiceAndEnv();
    }

    protected function resolveBasePath(): ?string
    {
        if (function_exists('app') && app() instanceof \Illuminate\Contracts\Foundation\Application) {
            return app()->basePath();
        }

        return realpath(__DIR__ . '/../../') ?: dirname(__DIR__, 2);
    }

    protected function initServiceAndEnv(): void
    {
        $service = env('LOGCORE_SERVICE');
        if ($service === null || $service === '') {
            $service = $this->resolveConfig('app.name', 'laravel');
        }
        $service = strtolower(trim((string)$service));
        $service = preg_replace('/[^a-z0-9._-]/', '-', $service) ?? 'laravel';
        $service = trim($service, '._-');
        if ($service === '') {
            $service = 'laravel';
        }
        $this->service = substr($service, 0, 63);

        $env = env('LOGCORE_ENV');
        if ($env === null || $env === '') {
            $env = $this->resolveConfig('app.env', 'dev');
        }
        $this->env = $this->normalizeEnv((string)$env);
    }

    protected function resolveConfig(string $key, mixed $default = null): mixed
    {
        try {
            if (function_exists('app') && app()->bound('config')) {
                return config($key, $default);
            }
        } catch (Throwable) {
            // Fall back to default
        }
        return $default;
    }

    protected function normalizeEnv(string $env): string
    {
        $env = strtolower(trim($env));
        return match ($env) {
            'production', 'prod' => 'prod',
            'staging', 'stage' => 'staging',
            'development', 'dev' => 'dev',
            'testing', 'test' => 'test',
            'local' => 'local',
            default => in_array($env, ['prod', 'staging', 'dev', 'test', 'local'], true) ? $env : 'dev',
        };
    }

    protected function write(LogRecord $record): void
    {
        try {
            $entry = $this->formatEntry($record);
            $this->transport->push($entry);
        } catch (Throwable $e) {
            fwrite(STDERR, "[Logcore] Error processing log record: " . $e->getMessage() . "\n");
        }
    }

    /**
     * @return array<string, mixed>
     */
    public function formatEntry(LogRecord $record): array
    {
        $timestamp = $record->datetime->setTimezone(new \DateTimeZone('UTC'))->format('Y-m-d\TH:i:s.v\Z');
        $severity = strtoupper($record->level->getName());

        $message = (string) $record->message;
        if (mb_strlen($message) > 65536) {
            $message = mb_substr($message, 0, 65536);
        }

        $context = $record->context;
        $exception = null;
        if (isset($context['exception']) && $context['exception'] instanceof Throwable) {
            $exception = $context['exception'];
            unset($context['exception']);
        }

        $cleanContext = $this->sanitizeContext($context);

        $canonicalJson = $this->canonicalJson($cleanContext);
        $rawInsertString = $timestamp . '|' . $this->service . '|' . $severity . '|' . $message . '|' . $canonicalJson;
        $insertId = substr(hash('sha256', $rawInsertString), 0, 32);

        $entry = [
            'timestamp' => $timestamp,
            'severity' => $severity,
            'message' => $message,
            'service' => $this->service,
            'env' => $this->env,
            'insert_id' => $insertId,
        ];

        $trace = LogcoreContext::getTrace();
        if ($trace !== null) {
            $entry['trace'] = $trace;
        }

        if ($exception !== null) {
            $entry['error'] = $this->formatError($exception, $message);
        }

        $labels = LogcoreContext::getLabels();
        if (!empty($labels)) {
            $entry['labels'] = $labels;
        }

        if (!empty($cleanContext)) {
            $entry['context'] = $cleanContext;
        }

        return $entry;
    }

    /**
     * @return array{type: string, message: string, stack: array<int, array{function: string, file: string, line: int, column: int, inApp: bool}>}
     */
    protected function formatError(Throwable $e, string $defaultMessage): array
    {
        $chain = [];
        $curr = $e;
        while ($curr !== null) {
            $chain[] = $curr;
            $curr = $curr->getPrevious();
        }
        $chain = array_reverse($chain);

        $frames = [];
        foreach ($chain as $ex) {
            $exFile = $ex->getFile();
            $exLine = $ex->getLine();
            $trace = $ex->getTrace();

            $throwFunction = '<throw>';
            if (!empty($trace) && isset($trace[0])) {
                $first = $trace[0];
                $throwFunction = isset($first['class'])
                    ? $first['class'] . ($first['type'] ?? '::') . $first['function']
                    : $first['function'];
            }

            $frames[] = [
                'function' => $throwFunction,
                'file' => $exFile,
                'line' => $exLine,
                'column' => 0,
                'inApp' => $this->isInApp($exFile),
            ];

            foreach ($trace as $frame) {
                if (count($frames) >= 50) {
                    break 2;
                }
                $file = $frame['file'] ?? '<internal>';
                $line = (int) ($frame['line'] ?? 0);
                $fn = isset($frame['class'])
                    ? $frame['class'] . ($frame['type'] ?? '::') . $frame['function']
                    : $frame['function'];

                $frames[] = [
                    'function' => $fn,
                    'file' => $file,
                    'line' => $line,
                    'column' => 0,
                    'inApp' => $this->isInApp($file),
                ];
            }
        }

        if (count($frames) > 50) {
            $frames = array_slice($frames, 0, 50);
        }

        $type = (new ReflectionClass($e))->getShortName();

        return [
            'type' => $type,
            'message' => $e->getMessage() !== '' ? $e->getMessage() : $defaultMessage,
            'stack' => $frames,
        ];
    }

    protected function isInApp(string $file): bool
    {
        if ($file === '<internal>' || $file === '<eval>') {
            return false;
        }

        if (str_contains($file, '/vendor/') || str_contains($file, '\\vendor\\')) {
            return false;
        }

        if ($this->basePath !== null && str_starts_with($file, $this->basePath)) {
            return true;
        }

        return false;
    }

    /**
     * @param array<mixed> $context
     * @return array<string, mixed>
     */
    protected function sanitizeContext(array $context): array
    {
        $clean = [];
        foreach ($context as $key => $val) {
            if (is_scalar($val) || is_null($val)) {
                $clean[(string)$key] = $val;
            } elseif (is_array($val)) {
                $clean[(string)$key] = $this->sanitizeContext($val);
            } elseif (is_object($val)) {
                if (method_exists($val, 'toArray')) {
                    $clean[(string)$key] = $this->sanitizeContext($val->toArray());
                } elseif (method_exists($val, '__toString')) {
                    $clean[(string)$key] = (string)$val;
                } else {
                    $clean[(string)$key] = get_class($val);
                }
            }
        }
        return $clean;
    }

    /**
     * @param array<string, mixed> $data
     */
    protected function canonicalJson(array $data): string
    {
        if (empty($data)) {
            return '{}';
        }
        $this->recursiveKsort($data);
        return json_encode($data, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) ?: '{}';
    }

    /**
     * @param array<mixed> $arr
     */
    private function recursiveKsort(array &$arr): void
    {
        ksort($arr);
        foreach ($arr as &$value) {
            if (is_array($value)) {
                $this->recursiveKsort($value);
            }
        }
    }
}
