<?php declare(strict_types=1);

namespace App\Logging;

use Monolog\Handler\AbstractProcessingHandler;
use Monolog\Level;
use Monolog\LogRecord;

class LogcoreHandler extends AbstractProcessingHandler
{
    /**
     * @param int|string|Level $level The minimum logging level at which this handler will be triggered
     * @param bool $bubble Whether the messages that are handled can bubble up the stack or not
     */
    public function __construct(
        int|string|Level $level = Level::Error,
        bool $bubble = true
    ) {
        parent::__construct($level, $bubble);
    }

    /**
     * Write the log record to Logcore transport.
     */
    protected function write(LogRecord $record): void
    {
        if (!LogcoreTransport::isEnabled()) {
            return;
        }

        try {
            $entry = LogcoreFormatter::formatRecord($record);
            if ($entry !== null) {
                LogcoreTransport::push($entry);
            }
        } catch (\Throwable $e) {
            // Never break application execution if formatting/push fails
            fwrite(STDERR, "[Logcore] Handler write error: " . $e->getMessage() . "\n");
        }
    }
}
