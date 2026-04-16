<?php

namespace App\Console\Commands;

use App\Models\EmailLog;
use Illuminate\Console\Command;

class MarkStaleEmailLogsAsFailed extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'email-logs:mark-stale-failed
                            {--minutes=60 : Minutes after which pending logs are considered stale}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Mark emails that have been pending for too long as failed (likely delivery failure)';

    /**
     * Execute the console command.
     */
    public function handle(): int
    {
        $minutes = (int) $this->option('minutes');
        $cutoff = now()->subMinutes($minutes);

        $updated = EmailLog::where('status', EmailLog::STATUS_PENDING)
            ->where('created_at', '<', $cutoff)
            ->update([
                'status' => EmailLog::STATUS_FAILED,
                'failure_reason' => 'Marked as failed: delivery may have failed (pending for ' . $minutes . '+ minutes)',
            ]);

        if ($updated > 0) {
            $this->info("Marked {$updated} stale email log(s) as failed.");
        }

        return Command::SUCCESS;
    }
}
