<?php declare(strict_types=1);

namespace App\Logging;

use Monolog\Handler\AbstractProcessingHandler;
use Monolog\Level;
use Monolog\LogRecord;

class LogcoreHandler extends AbstractProcessingHandler
{
    private const MAX_MESSAGE_LENGTH = 65536;
    private const MAX_STACK_FRAMES = 50;
    private const MAX_LABELS = 32;
    private const MAX_LABEL_LENGTH = 1024;

    /**
     * @param int|string|Level $level The minimum logging level at which this handler will be triggered
     * @param bool $bubble Whether the messages that are handled can bubble up the stack or not
     */
    public function __construct(int|string|Level $level = Level::Error, bool $bubble = true)
    {
        if (is_string($level)) {
            try {
                $level = Level::fromName($level);
            } catch (\Throwable) {
                $level = Level::Error;
            }
        } elseif (is_int($level)) {
            $level = Level::tryFrom($level) ?? Level::Error;
        }

        $minSeverityEnv = env('LOGCORE_MIN_SEVERITY', 'ERROR');
        if (is_string($minSeverityEnv) && $minSeverityEnv !== '') {
            try {
                $level = Level::fromName($minSeverityEnv);
            } catch (\Throwable) {
                // Keep default
            }
        }

        parent::__construct($level, $bubble);
    }

    /**
     * Writes the record down to the log of the implementing handler.
     */
    protected function write(LogRecord $record): void
    {
        if ($record->level->value < Level::Error->value) {
            return;
        }

        try {
            $entry = $this->formatLogRecord($record);
            LogcoreTransport::getInstance()->enqueue($entry);
        } catch (\Throwable) {
            // Logging must never break or block the application
        }
    }

    /**
     * Format a Monolog LogRecord into a Logcore-compliant entry array.
     *
     * @return array<string, mixed>
     */
    public function formatLogRecord(LogRecord $record): array
    {
        $transport = LogcoreTransport::getInstance();
        $service = $transport->getService();
        $env = $transport->getEnv();

        $timestamp = $record->datetime->setTimezone(new \DateTimeZone('UTC'))->format('Y-m-d\TH:i:s.v\Z');
        $severity = $record->level->getName();
        $message = mb_substr($record->message, 0, self::MAX_MESSAGE_LENGTH);

        // Extract and clean context
        $context = $record->context;
        $throwable = null;

        if (isset($context['exception']) && $context['exception'] instanceof \Throwable) {
            $throwable = $context['exception'];
            unset($context['exception']);
        } elseif (isset($context['error']) && $context['error'] instanceof \Throwable) {
            $throwable = $context['error'];
            unset($context['error']);
        }

        $sanitizedContext = $this->sanitizeData($context);

        // Canonical JSON for insert_id computation
        $canonicalContext = '';
        if (!empty($sanitizedContext)) {
            $encoded = json_encode($sanitizedContext, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
            $canonicalContext = $encoded !== false ? $encoded : '';
        }

        // insert_id: sha256(timestamp | service | severity | message | canonical_json(context))[:32]
        $rawInsertId = hash('sha256', $timestamp . '|' . $service . '|' . $severity . '|' . $message . '|' . $canonicalContext);
        $insertId = substr($rawInsertId, 0, 32);

        $entry = [
            'timestamp' => $timestamp,
            'severity' => $severity,
            'message' => $message,
            'service' => $service,
            'env' => $env,
            'insert_id' => $insertId,
        ];

        // Trace
        $trace = null;
        if (
            isset($record->context['trace']) &&
            is_array($record->context['trace']) &&
            isset($record->context['trace']['traceId'], $record->context['trace']['spanId'])
        ) {
            $trace = [
                'traceId' => (string) $record->context['trace']['traceId'],
                'spanId' => (string) $record->context['trace']['spanId'],
                'sampled' => (bool) ($record->context['trace']['sampled'] ?? true),
            ];
        } else {
            $trace = LogcoreTraceContext::getCurrentTrace() ?? LogcoreTraceContext::getOrCreateTrace();
        }

        if ($trace !== null) {
            $entry['trace'] = $trace;
        }

        // Error with parsed frames
        if ($throwable !== null) {
            $entry['error'] = $this->formatThrowable($throwable);
        }

        // Labels
        $labels = $this->extractLabels($record);
        if (!empty($labels)) {
            $entry['labels'] = $labels;
        }

        // Context
        if (!empty($sanitizedContext)) {
            $entry['context'] = $sanitizedContext;
        }

        return $entry;
    }

    /**
     * Format a Throwable into type, message, and parsed innermost-first stack frames.
     *
     * @return array{type: string, message: string, stack: array<int, array{function: string, file: string, line: int, column: int, inApp: bool}>}
     */
    protected function formatThrowable(\Throwable $throwable): array
    {
        // Follow exception chain to its root
        $chain = [];
        $curr = $throwable;
        while ($curr !== null) {
            $chain[] = $curr;
            $curr = $curr->getPrevious();
        }

        // Root exception is the deepest cause; frames from root go first (innermost)
        $frames = [];
        $reversedChain = array_reverse($chain);

        foreach ($reversedChain as $ex) {
            $trace = $ex->getTrace();
            $frameCount = count($trace);

            // Innermost frame (where throw actually occurred)
            $topFunc = '<throw>';
            if (!empty($trace)) {
                $first = $trace[0];
                $topFunc = (isset($first['class']) ? $first['class'] . ($first['type'] ?? '::') : '') . ($first['function'] ?? '<throw>');
            }

            $frames[] = [
                'function' => $topFunc,
                'file' => $ex->getFile(),
                'line' => $ex->getLine(),
                'column' => 0,
                'inApp' => $this->isInApp($ex->getFile()),
            ];

            for ($i = 0; $i < $frameCount; $i++) {
                if (count($frames) >= self::MAX_STACK_FRAMES) {
                    break 2;
                }

                $currFrame = $trace[$i];
                $nextFrame = $trace[$i + 1] ?? null;

                $func = '<main>';
                if ($nextFrame !== null) {
                    $func = (isset($nextFrame['class']) ? $nextFrame['class'] . ($nextFrame['type'] ?? '::') : '') . ($nextFrame['function'] ?? '<main>');
                }

                $file = $currFrame['file'] ?? '<internal>';
                $line = $currFrame['line'] ?? 0;

                $frames[] = [
                    'function' => $func,
                    'file' => $file,
                    'line' => $line,
                    'column' => 0,
                    'inApp' => $this->isInApp($file),
                ];
            }
        }

        return [
            'type' => (new \ReflectionClass($throwable))->getShortName(),
            'message' => mb_substr($throwable->getMessage(), 0, self::MAX_MESSAGE_LENGTH),
            'stack' => array_slice($frames, 0, self::MAX_STACK_FRAMES),
        ];
    }

    /**
     * Determine if a file path is application code or dependency/vendor code.
     */
    protected function isInApp(string $file): bool
    {
        if (
            str_contains($file, '/vendor/') ||
            str_contains($file, '\\vendor\\') ||
            str_starts_with($file, '<') ||
            str_contains($file, 'eval()')
        ) {
            return false;
        }

        return true;
    }

    /**
     * Extract string labels from extra and context data.
     *
     * @return array<string, string>
     */
    protected function extractLabels(LogRecord $record): array
    {
        $labels = [];

        if (!empty($record->extra)) {
            foreach ($record->extra as $k => $v) {
                if (count($labels) >= self::MAX_LABELS) {
                    break;
                }
                if (is_scalar($v) || (is_object($v) && method_exists($v, '__toString'))) {
                    $key = mb_substr((string) $k, 0, self::MAX_LABEL_LENGTH);
                    $val = mb_substr((string) $v, 0, self::MAX_LABEL_LENGTH);
                    $labels[$key] = $val;
                }
            }
        }

        return $labels;
    }

    /**
     * Sanitize data to ensure JSON-serializability without recursion issues.
     */
    protected function sanitizeData(mixed $data, int $depth = 0): mixed
    {
        if ($depth > 5) {
            return '[max_depth]';
        }

        if (is_null($data) || is_scalar($data)) {
            return $data;
        }

        if (is_resource($data)) {
            return '[resource:' . get_resource_type($data) . ']';
        }

        if ($data instanceof \DateTimeInterface) {
            return $data->format(\DateTimeInterface::ATOM);
        }

        if ($data instanceof \JsonSerializable) {
            return $this->sanitizeData($data->jsonSerialize(), $depth + 1);
        }

        if (is_array($data)) {
            $result = [];
            foreach ($data as $k => $v) {
                $result[(string) $k] = $this->sanitizeData($v, $depth + 1);
            }
            return $result;
        }

        if (is_object($data)) {
            if (method_exists($data, 'toArray')) {
                return $this->sanitizeData($data->toArray(), $depth + 1);
            }
            if (method_exists($data, '__toString')) {
                return (string) $data;
            }
            return get_class($data);
        }

        return (string) $data;
    }

    public function close(): void
    {
        parent::close();
        try {
            LogcoreTransport::getInstance()->flush();
        } catch (\Throwable) {
            // Swallow
        }
    }

    public function __destruct()
    {
        try {
            $this->close();
        } catch (\Throwable) {
            // Swallow
        }
    }
}
