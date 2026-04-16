<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class BackfillUserProfileFirstLastName extends Command
{
    protected $signature = 'user-profiles:backfill-first-last-name';

    protected $description = 'Split existing full_name into first_name and last_name for user_profiles';

    public function handle(): int
    {
        $count = 0;

        DB::table('user_profiles')
            ->whereNotNull('full_name')
            ->where(function ($q) {
                $q->whereNull('first_name')->orWhereNull('last_name');
            })
            ->orderBy('id')
            ->chunk(100, function ($profiles) use (&$count) {
                foreach ($profiles as $profile) {
                    $fullName = trim($profile->full_name ?? '');
                    if ($fullName === '') {
                        continue;
                    }
                    $parts = preg_split('/\s+/', $fullName, 2);
                    $firstName = $parts[0] ?? null;
                    $lastName = $parts[1] ?? null;

                    DB::table('user_profiles')
                        ->where('id', $profile->id)
                        ->update([
                            'first_name' => $firstName,
                            'last_name' => $lastName,
                        ]);
                    $count++;
                }
            });

        $this->info("Backfilled first_name and last_name for {$count} user profile(s).");
        return self::SUCCESS;
    }
}
