<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

// Daily payout run. Deliberately NOT withoutOverlapping()'d here — the whole point of
// this exercise is that the payout pipeline must be safe even when this runs more than
// once concurrently (overlapping schedules, a manual re-trigger, two servers). See
// PayoutService for where that safety actually lives.
Schedule::command('payouts:run')->dailyAt('02:00');

// Keeps the Filament dashboard's "earned to date" column fresh without making every
// page load recompute it live across every instructor's revenue_allocations.
Schedule::command('payouts:refresh-earnings')->everyFiveMinutes();
