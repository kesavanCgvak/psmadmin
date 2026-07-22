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

// Mark email logs pending for 60+ minutes as failed (likely delivery failure)
Schedule::command('email-logs:mark-stale-failed --minutes=60')->hourly();

// Reconcile provider inventory trial incentives (safety net for missed hooks)
Schedule::command('subscriptions:evaluate-trial-incentives')->daily();
