<?php declare(strict_types=1);

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote')->hourly();

Artisan::command('logcore:probe', function () {
    \App\Logging\LogcoreTransport::setSender(function (array $payload): bool {
        foreach ($payload['entries'] as $entry) {
            $this->output->writeln(json_encode($entry, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
        }
        return true;
    });

    try {
        throw new \RuntimeException('Logcore probe test error');
    } catch (\Throwable $e) {
        \Illuminate\Support\Facades\Log::error('Logcore probe test error', [
            'exception' => $e,
            'probe' => true,
        ]);
    }

    \App\Logging\LogcoreTransport::getInstance()->flush();
})->purpose('Emit a test error log for Logcore integration verification');
