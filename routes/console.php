<?php declare(strict_types=1);

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote')->hourly();

Artisan::command('logcore:probe', function () {
    $entryJson = null;
    $transport = \App\Logging\LogcoreTransport::getInstance();
    $transport->setSender(function (array $payload) use (&$entryJson) {
        if (!empty($payload['entries'])) {
            $entryJson = json_encode($payload['entries'][0], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        }
        return true;
    });

    \Illuminate\Support\Facades\Log::error('Logcore probe test error');
    $transport->flush();

    if ($entryJson !== null) {
        $this->output->writeln($entryJson);
    }
})->purpose('Emit a test error log for Logcore integration verification');
