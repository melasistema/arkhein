<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;
use App\Services\ProactiveService;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

/*
|--------------------------------------------------------------------------
| Arkhein Heartbeat
|--------------------------------------------------------------------------
| The scheduler pulses the proactive service every minute to check
| for active habits, patterns, and scheduled system suggestions.
*/
Schedule::call(function (ProactiveService $proactive) {
    $proactive->pulse();
})->everyMinute();
