<?php

namespace App\Console\Commands;

use App\Models\SupplyJob;
use App\Models\SupplyJobRatingReminder;
use Carbon\Carbon;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Mail;

class SendRenterRatingReminders extends Command
{
    /**
     * Reminder schedule: every 7 days after completed_at (up to 30 days).
     */
    protected const REMINDER_DAYS = [7, 14, 21, 30];

    protected $signature = 'supply-jobs:send-renter-rating-reminders';

    protected $description = 'Send reminder emails to renters to rate the job (every 7 days: 7, 14, 21, 30 days after completed date)';

    public function handle(): int
    {
        $today = Carbon::today();
        $sent = 0;

        $jobs = SupplyJob::with([
            'rentalJob.user.profile',
            'rentalJob.user.company.defaultContact.profile',
            'providerCompany:id,name',
            'jobRating',
        ])
            ->where('status', 'completed_pending_rating')
            ->whereNotNull('completed_at')
            ->get();

        foreach ($jobs as $supplyJob) {
            // Skip if renter already rated this supply job
            if ($supplyJob->jobRating && $supplyJob->jobRating->rated_at) {
                continue;
            }

            $completedAt = Carbon::parse($supplyJob->completed_at)->startOfDay();
            if ($completedAt->isFuture()) {
                continue;
            }

            $email = $this->getRenterEmail($supplyJob);
            if (!$email) {
                continue;
            }

            $sentReminderDays = $supplyJob->ratingReminders()->pluck('days_after_completed')->all();

            foreach (self::REMINDER_DAYS as $days) {
                if (in_array($days, $sentReminderDays, true)) {
                    continue;
                }
                $reminderDate = $completedAt->copy()->addDays($days);
                if ($today->lt($reminderDate)) {
                    continue;
                }

                $this->sendReminder($supplyJob, $days, $completedAt, $email);
                SupplyJobRatingReminder::create([
                    'supply_job_id' => $supplyJob->id,
                    'days_after_completed' => $days,
                    'sent_at' => now(),
                ]);
                $sent++;
            }
        }

        if ($sent > 0) {
            $this->info("Sent {$sent} renter rating reminder(s).");
        }

        return self::SUCCESS;
    }

    private function getRenterEmail(SupplyJob $supplyJob): ?string
    {
        $rentalJob = $supplyJob->rentalJob;
        if (!$rentalJob) {
            return null;
        }
        $user = $rentalJob->user;
        if ($user?->profile?->email) {
            return $user->profile->email;
        }
        if ($user?->company_id) {
            $company = $user->company;
            return data_get($company, 'defaultContact.profile.email');
        }
        return null;
    }

    private function sendReminder(SupplyJob $supplyJob, int $daysAfterCompleted, Carbon $completedAt, string $email): void
    {
        $rentalJob = $supplyJob->rentalJob;
        $daysSinceCompleted = $completedAt->diffInDays(Carbon::today());
        $labels = [
            7 => 'first',
            14 => 'second',
            21 => 'third',
            30 => 'final',
        ];

        $mailContent = [
            'rental_job_name' => $rentalJob->name ?? 'Rental Job',
            'provider_name' => $supplyJob->providerCompany->name ?? 'Provider',
            'completed_date' => $completedAt->format('d M Y'),
            'days_since_completed' => $daysSinceCompleted,
            'reminder_label' => $labels[$daysAfterCompleted] ?? 'follow-up',
        ];

        \App\Helpers\EmailHelper::send('jobRatingReminder', $mailContent, function ($message) use ($email) {
            $message->to($email)
                ->from(config('mail.from.address'), config('mail.from.name'));
        });
    }
}
