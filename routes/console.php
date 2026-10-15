<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

// Balances change as money moves, but re-check floats regularly as a safety net.
Schedule::command('treasury:check-floats')->everyFiveMinutes()->withoutOverlapping();
