<?php declare(strict_types=1);

namespace App\Logging;

use Monolog\Handler\AbstractProcessingHandler;
use Monolog\Level;
use Monolog\LogRecord;

class LogcoreHandler extends AbstractProcessingHandler
{
    protected LogcoreTransport $transport;
    protected LogcoreFormatter $formatterInstance;

    public function __construct(
        int|string|Level $level = Level::Error,
        bool $bubble = true,
        ?LogcoreTransport $transport = null,
        ?LogcoreFormatter $formatter = null
    ) {
        parent::__construct($level, $bubble);
        $this->transport = $transport ?? LogcoreTransport::getInstance();
        $this->formatterInstance = $formatter ?? new LogcoreFormatter();
    }

    protected function write(LogRecord $record): void
    {
        try {
            $entry = $this->formatterInstance->formatRecord($record);
            $this->transport->push($entry);
        } catch (\Throwable) {
            // Logging must never break the application
        }
    }

    public function close(): void
    {
        try {
            $this->transport->flush();
        } catch (\Throwable) {
        }
        parent::close();
    }

    public function reset(): void
    {
        try {
            $this->transport->flush();
        } catch (\Throwable) {
        }
        parent::reset();
    }
}
