<?php declare(strict_types=1);

namespace App\Logcore;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;
use RuntimeException;

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
    protected $description = 'Emit one test ERROR log entry through Logcore integration for validation';

    /**
     * Execute the console command.
     */
    public function handle(): int
    {
        $capturedPayload = null;

        $transport = LogcoreTransport::getInstance();
        $transport->setCaptureCallback(function (string $json, array $array) use (&$capturedPayload) {
            $capturedPayload = $json;
        });

        try {
            $syntheticException = new RuntimeException('Logcore probe synthetic exception');
            Log::error('Logcore probe test error message', [
                'probe' => true,
                'exception' => $syntheticException,
            ]);

            $transport->flush();
        } finally {
            $transport->setCaptureCallback(null);
        }

        if ($capturedPayload === null) {
            $this->error('Failed to capture Logcore emission.');
            return 1;
        }

        $this->line($capturedPayload);
        return 0;
    }
}
