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

// Supplier SMS becomes eligible on the day the rental start date enters the
// 0–7 day window. 00:15 UTC, after the other midnight tasks. The overlap
// lock expires after 10 minutes so a crashed run does not block the next day.
Schedule::command('sms:send-eligible-supplier-notifications')
    ->dailyAt('00:15')
    ->withoutOverlapping(10);

// Shared hosting cannot keep a daemon alive. The existing once-a-minute cron
// (cron.php -> schedule:run) drains the database queue, then exits.
// Job-level $tries still override --tries. --max-time stops a long run.
Schedule::command(
    'queue:work --queue=default --stop-when-empty --tries=3 --max-time=50'
)
    ->everyMinute()
    ->withoutOverlapping();
