<?php declare(strict_types=1);

namespace App\Logging;

use Monolog\Handler\AbstractProcessingHandler;
use Monolog\Level;
use Monolog\LogRecord;

class LogcoreHandler extends AbstractProcessingHandler
{
    protected LogcoreFormatter $logcoreFormatter;
    protected LogcoreTransport $logcoreTransport;

    /**
     * @param int|string|Level $level
     * @param bool $bubble
     * @param LogcoreFormatter|null $formatter
     * @param LogcoreTransport|null $transport
     */
    public function __construct(
        int|string|Level $level = Level::Error,
        bool $bubble = true,
        ?LogcoreFormatter $formatter = null,
        ?LogcoreTransport $transport = null
    ) {
        parent::__construct($level, $bubble);
        $this->logcoreFormatter = $formatter ?? new LogcoreFormatter();
        $this->logcoreTransport = $transport ?? LogcoreTransport::getInstance();
    }

    /**
     * {@inheritdoc}
     */
    public function isHandling(LogRecord $record): bool
    {
        if (!parent::isHandling($record)) {
            return false;
        }

        // If capture mode is active, always handle
        if (LogcoreTransport::isCaptureActive()) {
            return true;
        }

        // If explicitly disabled in environment, do not handle
        $enabled = env('LOGCORE_ENABLED');
        if ($enabled === false || $enabled === 'false' || $enabled === '0') {
            return false;
        }

        return true;
    }

    /**
     * {@inheritdoc}
     */
    protected function write(LogRecord $record): void
    {
        $entry = $this->logcoreFormatter->formatRecord($record);
        $this->logcoreTransport->push($entry);
    }
}
