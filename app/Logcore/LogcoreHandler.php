<?php declare(strict_types=1);

namespace App\Logcore;

use DateTimeZone;
use Monolog\Handler\AbstractProcessingHandler;
use Monolog\Level;
use Monolog\LogRecord;
use Throwable;

class LogcoreHandler extends AbstractProcessingHandler
{
    private LogcoreTransport $transport;
    private string $service;
    private string $env;

    public function __construct(
        int|string|Level $level = Level::Error,
        bool $bubble = true,
        ?LogcoreTransport $transport = null
    ) {
        parent::__construct($level, $bubble);
        $this->transport = $transport ?? LogcoreTransport::getInstance();

        $rawService = (string) (env('LOGCORE_SERVICE') ?: config('app.name', 'laravel'));
        $service = strtolower((string) preg_replace('/[^a-zA-Z0-9._-]/', '-', $rawService));
        $service = trim($service, '.-_');
        if ($service === '' || !preg_match('/^[a-z0-9]/', $service)) {
            $service = 'laravel-app';
        }
        $this->service = substr($service, 0, 63);

        $rawEnv = strtolower((string) (env('LOGCORE_ENV') ?: config('app.env', 'dev')));
        $envMap = [
            'production' => 'prod',
            'prod' => 'prod',
            'staging' => 'staging',
            'development' => 'dev',
            'dev' => 'dev',
            'testing' => 'test',
            'test' => 'test',
            'local' => 'local',
        ];
        $this->env = $envMap[$rawEnv] ?? 'dev';
    }

    protected function write(LogRecord $record): void
    {
        try {
            $entry = $this->formatEntry($record);
            $this->transport->enqueue($entry);
        } catch (Throwable) {
            // Logging must never crash the host application
        }
    }

    /**
     * @return array<string, mixed>
     */
    public function formatEntry(LogRecord $record): array
    {
        $timestamp = $record->datetime->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d\TH:i:s.v\Z');
        $severity = strtoupper($record->level->getName());

        $message = (string) $record->message;
        if ($message === '') {
            $message = 'Empty log message';
        }
        $message = mb_substr($message, 0, 65536);

        $context = $record->context;
        $exception = null;
        if (isset($context['exception']) && $context['exception'] instanceof Throwable) {
            $exception = $context['exception'];
            unset($context['exception']);
        }

        $sanitizedContext = $this->sanitizeContext($context);

        $canonicalContext = !empty($sanitizedContext)
            ? json_encode($sanitizedContext, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)
            : '';
        $rawString = $timestamp . '|' . $this->service . '|' . $severity . '|' . $message . '|' . ($canonicalContext ?: '');
        $insertId = substr(hash('sha256', $rawString), 0, 32);

        $entry = [
            'timestamp' => $timestamp,
            'severity' => $severity,
            'message' => $message,
            'service' => $this->service,
            'env' => $this->env,
            'insert_id' => $insertId,
        ];

        // Trace context (only when valid real trace data exists)
        $trace = LogcoreTraceContext::get();
        if ($trace !== null && !empty($trace['traceId'])) {
            $traceObj = ['traceId' => $trace['traceId']];
            if (!empty($trace['spanId'])) {
                $traceObj['spanId'] = $trace['spanId'];
            }
            if (isset($trace['sampled'])) {
                $traceObj['sampled'] = (bool) $trace['sampled'];
            }
            $entry['trace'] = $traceObj;
        }

        // Error parsing (only when real throwable exists)
        if ($exception !== null) {
            $entry['error'] = $this->parseError($exception);
        }

        // Labels (max 32, value max 1024 chars)
        if (!empty($record->extra)) {
            $labels = [];
            $count = 0;
            foreach ($record->extra as $key => $val) {
                if ($count >= 32) {
                    break;
                }
                $strKey = (string) $key;
                if (preg_match('/^[a-zA-Z0-9_-]{1,64}$/', $strKey)) {
                    $labels[$strKey] = mb_substr(is_scalar($val) ? (string) $val : (json_encode($val) ?: ''), 0, 1024);
                    $count++;
                }
            }
            if (!empty($labels)) {
                $entry['labels'] = $labels;
            }
        }

        if (!empty($sanitizedContext)) {
            $entry['context'] = $sanitizedContext;
        }

        return $entry;
    }

    /**
     * @return array{type: string, message: string, stack: array<int, array{function: string, file: ?string, line: ?int, column: ?int, inApp: bool}>}
     */
    private function parseError(Throwable $e): array
    {
        $type = mb_substr(get_class($e), 0, 256);
        $message = mb_substr($e->getMessage() ?: get_class($e), 0, 65536);

        $frames = [];

        // Innermost frame: throw location
        $throwFile = $e->getFile();
        $throwLine = $e->getLine();
        $trace = $e->getTrace();

        $fn0 = (isset($trace[0]['class']) ? $trace[0]['class'] . ($trace[0]['type'] ?? '::') : '') . ($trace[0]['function'] ?? '<throw>');
        $normalizedThrowFile = $throwFile ? str_replace('\\', '/', $throwFile) : '';
        $frames[] = [
            'function' => mb_substr($fn0 ?: '<throw>', 0, 256),
            'file' => $throwFile ? mb_substr($throwFile, 0, 1024) : null,
            'line' => $throwLine > 0 ? (int) $throwLine : null,
            'column' => null,
            'inApp' => $normalizedThrowFile !== '' ? !str_contains($normalizedThrowFile, '/vendor/') : true,
        ];

        for ($i = 0; $i < count($trace); $i++) {
            if (count($frames) >= 50) {
                break;
            }
            $t = $trace[$i];
            $next = $trace[$i + 1] ?? null;
            $fn = (isset($next['class']) ? $next['class'] . ($next['type'] ?? '::') : '') . ($next['function'] ?? $t['function']);
            $file = $t['file'] ?? null;
            $line = isset($t['line']) && (int) $t['line'] > 0 ? (int) $t['line'] : null;
            $normalizedFile = $file ? str_replace('\\', '/', $file) : '';

            $frames[] = [
                'function' => mb_substr($fn ?: '<unknown>', 0, 256),
                'file' => $file ? mb_substr($file, 0, 1024) : null,
                'line' => $line,
                'column' => null,
                'inApp' => $normalizedFile !== '' ? !str_contains($normalizedFile, '/vendor/') : false,
            ];
        }

        return [
            'type' => $type,
            'message' => $message,
            'stack' => array_slice($frames, 0, 50),
        ];
    }

    /**
     * @param array<mixed> $context
     * @return array<mixed>
     */
    private function sanitizeContext(array $context): array
    {
        $sanitized = [];
        foreach ($context as $key => $value) {
            $sanitizedKey = (string) $key;
            if (is_scalar($value) || is_null($value)) {
                $sanitized[$sanitizedKey] = $value;
            } elseif (is_array($value)) {
                $sanitized[$sanitizedKey] = $this->sanitizeContext($value);
            } elseif ($value instanceof \JsonSerializable) {
                $sanitized[$sanitizedKey] = $value->jsonSerialize();
            } elseif (is_object($value)) {
                if (method_exists($value, 'toArray')) {
                    $sanitized[$sanitizedKey] = $value->toArray();
                } else {
                    $sanitized[$sanitizedKey] = get_class($value);
                }
            } else {
                $sanitized[$sanitizedKey] = (string) $value;
            }
        }
        return $sanitized;
    }
}
