<?php declare(strict_types=1);

namespace App\Console\Commands;

use App\Logging\LogcoreTransport;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;
use RuntimeException;
use Throwable;

class LogcoreProbeCommand extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'logcore:probe';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Emits one structured error log entry to test Logcore logging integration.';

    /**
     * Execute the console command.
     */
    public function handle(): int
    {
        $captured = null;
        LogcoreTransport::setCaptureHandler(function (array $envelope) use (&$captured) {
            $captured = $envelope;
        });

        try {
            try {
                throw new RuntimeException('Logcore probe verification error');
            } catch (Throwable $e) {
                Log::error('Logcore integration probe test error', [
                    'probe' => true,
                    'exception' => $e,
                ]);
            }

            LogcoreTransport::getInstance()->flush();
        } finally {
            LogcoreTransport::setCaptureHandler(null);
        }

        if ($captured === null) {
            $this->error('Failed to capture logcore emission.');
            return 1;
        }

        $json = json_encode($captured, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        $this->output->write($json . "\n");

        return 0;
    }
}
