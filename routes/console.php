<?php declare(strict_types=1);

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote')->hourly();

Artisan::command('logcore:probe', function () {
    \Illuminate\Support\Facades\Log::error('Logcore probe test error', [
        'probe' => true,
        'source' => 'artisan:logcore:probe',
    ]);
    \App\Logging\LogcoreTransport::flush();
    $entry = \App\Logging\LogcoreTransport::getLastEmittedEntry();
    if ($entry !== null) {
        $this->line(json_encode($entry, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
    }
})->purpose('Emit a test error log entry to verify Logcore integration');
