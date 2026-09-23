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
    protected $description = 'Emit a test error log entry to verify Logcore integration';

    /**
     * Execute the console command.
     */
    public function handle(): int
    {
        Log::error('Logcore probe test error', [
            'probe' => true,
            'source' => 'artisan:logcore:probe',
        ]);

        LogcoreTransport::flush();

        $entry = LogcoreTransport::getLastEmittedEntry();
        if ($entry !== null) {
            $this->line(json_encode($entry, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
        }

        return Command::SUCCESS;
    }
}
