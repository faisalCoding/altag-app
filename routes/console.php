<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

use Illuminate\Support\Facades\Schedule;

Schedule::command('db:backup')->daily();

// Weekly progress digest for guardians, sent every Saturday morning.
Schedule::command('guardian:weekly-digest')->weeklyOn(6, '07:00');

// Flip cached student statuses when a scheduled change (e.g. auto-return from
// suspension) reaches its effective date.
Schedule::command('students:sync-current-status')->dailyAt('00:10');

// Close out bus bookings whose prepayment deadline passed unpaid. Their buses
// are free from midnight regardless; this writes the cancellation down.
Schedule::command('bus:expire-unpaid')->dailyAt('00:15');
