<?php

namespace App\Console\Commands;

use App\Models\SupplyJob;
use App\Models\SupplyJobCompletionReminder;
use Carbon\Carbon;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Mail;

class SendSupplyJobCompletionReminders extends Command
{
    /**
     * Reminder schedule: days after unpack date (follow-up up to 30 days).
     */
    protected const REMINDER_DAYS = [2, 7, 14, 21, 30];

    protected $signature = 'supply-jobs:send-completion-reminders';

    protected $description = 'Send reminder emails to providers to complete the job (2, 7, 14, 21, 30 days after unpack date)';

    public function handle(): int
    {
        $today = Carbon::today();
        $sent = 0;

        $jobs = SupplyJob::with(['provider.defaultContact.profile', 'rentalJob'])
            ->where('status', 'accepted')
            ->whereNotNull('unpacking_date')
            ->get();

        foreach ($jobs as $supplyJob) {
            $unpackDate = Carbon::parse($supplyJob->unpacking_date)->startOfDay();
            if ($unpackDate->isFuture()) {
                continue;
            }

            $email = data_get($supplyJob, 'provider.defaultContact.profile.email');
            if (!$email) {
                continue;
            }

            $sentReminderDays = $supplyJob->completionReminders()->pluck('days_after_unpack')->all();

            foreach (self::REMINDER_DAYS as $days) {
                if (in_array($days, $sentReminderDays, true)) {
                    continue;
                }
                $reminderDate = $unpackDate->copy()->addDays($days);
                if ($today->lt($reminderDate)) {
                    continue;
                }

                $this->sendReminder($supplyJob, $days, $unpackDate, $email);
                SupplyJobCompletionReminder::create([
                    'supply_job_id' => $supplyJob->id,
                    'days_after_unpack' => $days,
                    'sent_at' => now(),
                ]);
                $sent++;
            }
        }

        if ($sent > 0) {
            $this->info("Sent {$sent} completion reminder(s).");
        }

        return self::SUCCESS;
    }

    private function sendReminder(SupplyJob $supplyJob, int $daysAfterUnpack, Carbon $unpackDate, string $email): void
    {
        $rentalJob = $supplyJob->rentalJob;
        $daysSinceUnpack = $unpackDate->diffInDays(Carbon::today());
        $labels = [
            2 => 'first',
            7 => 'second',
            14 => 'third',
            21 => 'fourth',
            30 => 'final',
        ];

        $mailContent = [
            'rental_job_name' => $rentalJob->name ?? 'Rental Job',
            'unpack_date' => $unpackDate->format('d M Y'),
            'days_since_unpack' => $daysSinceUnpack,
            'reminder_label' => $labels[$daysAfterUnpack] ?? 'follow-up',
        ];

        Mail::send('emails.jobCompletionReminder', $mailContent, function ($message) use ($email) {
            $message->to($email)
                ->subject('Reminder: Please complete your job - Pro Subrental Marketplace')
                ->from(config('mail.from.address'), config('mail.from.name'));
        });
    }
}
