<?php declare(strict_types=1);

namespace App\Console\Commands;

use App\Logging\LogcoreTransport;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

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
    protected $description = 'Emit a test error log for Logcore integration verification';

    /**
     * Execute the console command.
     */
    public function handle(): int
    {
        LogcoreTransport::setSender(function (array $payload): bool {
            foreach ($payload['entries'] as $entry) {
                $this->output->writeln(json_encode($entry, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
            }
            return true;
        });

        try {
            throw new \RuntimeException('Logcore probe test error');
        } catch (\Throwable $e) {
            Log::error('Logcore probe test error', [
                'exception' => $e,
                'probe' => true,
            ]);
        }

        LogcoreTransport::getInstance()->flush();

        return Command::SUCCESS;
    }
}
