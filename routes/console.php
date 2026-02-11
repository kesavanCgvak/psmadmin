<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

// Provider completion reminders: 2, 7, 14, 21, 30 days after unpack date
Schedule::command('supply-jobs:send-completion-reminders')->daily();

// Renter rating reminders: every 7 days (7, 14, 21, 30 days after completed date)
Schedule::command('supply-jobs:send-renter-rating-reminders')->daily();
