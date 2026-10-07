<?php declare(strict_types=1);

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote')->hourly();

Artisan::command('logcore:probe', function () {
    $command = new \App\Logcore\LogcoreProbeCommand();
    $command->setOutput($this->output);
    return $command->handle();
})->purpose('Emit one test ERROR log entry through Logcore integration for validation');
