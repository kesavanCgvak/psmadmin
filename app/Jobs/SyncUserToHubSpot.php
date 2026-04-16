<?php

namespace App\Jobs;

use App\Models\User;
use App\Services\HubSpotService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Log;

class SyncUserToHubSpot implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $userId;

    /**
     * Create a new job instance.
     */
    public function __construct(int $userId)
    {
        $this->userId = $userId;
    }

    /**
     * Execute the job.
     */
    public function handle(HubSpotService $hubSpotService): void
    {
        $user = User::with('profile')->find($this->userId);

        if (!$user) {
            Log::warning('HubSpot sync skipped: user not found.', [
                'user_id' => $this->userId,
            ]);
            return;
        }

        Log::info('HubSpot sync job started for user.', [
            'user_id' => $user->id,
            'email_verified' => $user->email_verified,
        ]);

        // Only sync verified users
        if (!$user->email_verified) {
            Log::info('HubSpot sync skipped: user not email-verified.', [
                'user_id' => $user->id,
                'email_verified' => $user->email_verified,
            ]);
            return;
        }

        $email = $user->preferred_email;

        if (empty($email)) {
            Log::warning('HubSpot sync skipped: user has no email.', [
                'user_id' => $user->id,
            ]);
            return;
        }

        $exists = $hubSpotService->contactExists($email);

        // If we know the contact exists, do not create it again.
        if ($exists === true) {
            Log::info('HubSpot contact already exists, skipping creation.', [
                'user_id' => $user->id,
                'email' => $email,
            ]);
            return;
        }

        // If existence is unknown due to API/config issues, log and bail out to avoid duplicates.
        if ($exists === null) {
            Log::warning('HubSpot contact existence unknown, skipping creation to avoid duplicates.', [
                'user_id' => $user->id,
                'email' => $email,
            ]);
            return;
        }

        $configProps = Config::get('hubspot.properties', []);

        $fullName = $user->profile->full_name ?? $user->username ?? null;
        $phone = $user->profile->mobile ?? null;
        $userType = $user->account_type ?? $user->company->account_type ?? null;

        // Split full name into first/last name for HubSpot if possible
        $firstName = null;
        $lastName = null;
        if ($fullName) {
            $parts = preg_split('/\s+/', trim($fullName), 2);
            $firstName = $parts[0] ?? null;
            $lastName = $parts[1] ?? null;
        }

        $properties = [
            $configProps['email'] ?? 'email' => $email,
        ];

        // if (!empty($fullName) && !empty($configProps['full_name'])) {
        //     $properties[$configProps['full_name']] = $fullName;
        // }

        if (!empty($firstName) && !empty($configProps['firstname'])) {
            $properties[$configProps['firstname']] = $firstName;
        }

        if (!empty($lastName) && !empty($configProps['lastname'])) {
            $properties[$configProps['lastname']] = $lastName;
        }

        if (!empty($phone) && !empty($configProps['phone'])) {
            $properties[$configProps['phone']] = $phone;
        }

        if (!empty($userType) && !empty($configProps['user_type'])) {
            $properties[$configProps['user_type']] = $userType;
        }

        Log::info('HubSpot sync prepared properties.', [
            'user_id' => $user->id,
            'email' => $email,
            'properties' => $properties,
        ]);

        $created = $hubSpotService->createContact($properties);

        if (!$created) {
            // Error already logged in service; do not throw to avoid impacting user flow.
            Log::warning('HubSpot contact creation reported failure.', [
                'user_id' => $user->id,
                'email' => $email,
            ]);
            return;
        }

        Log::info('HubSpot sync job completed successfully for user.', [
            'user_id' => $user->id,
            'email' => $email,
        ]);
    }
}

