<?php declare(strict_types=1);

use App\Logging\LogcoreTransport;
use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Log;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote')->hourly();

Artisan::command('logcore:probe', function () {
    $capturedEntry = null;
    $transport = LogcoreTransport::getInstance();
    $transport->setHttpClient(function (string $url, array $headers, string $body) use (&$capturedEntry) {
        $decoded = json_decode($body, true);
        if (is_array($decoded) && isset($decoded['entries'][0]) && is_array($decoded['entries'][0])) {
            $capturedEntry = $decoded['entries'][0];
        }
        return ['statusCode' => 200, 'body' => '{"status":"ok"}'];
    });

    Log::error('Logcore probe test error', [
        'probe' => true,
    ]);

    $transport->flush();

    if ($capturedEntry !== null) {
        $this->line(json_encode($capturedEntry, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
    }
})->purpose('Emit a test error log entry to Logcore');
