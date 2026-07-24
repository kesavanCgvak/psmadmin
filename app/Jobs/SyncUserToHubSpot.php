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
use Illuminate\Support\Str;

class SyncUserToHubSpot implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $userId;

    public ?string $correlationId;

    /**
     * Create a new job instance.
     */
    public function __construct(int $userId, ?string $correlationId = null)
    {
        $this->userId = $userId;
        $this->correlationId = $correlationId;
    }

    /**
     * Execute the job.
     */
    public function handle(HubSpotService $hubSpotService): void
    {
        $correlationId = $this->correlationId ?: (string) Str::uuid();
        $contextBase = [
            'correlation_id' => $correlationId,
            'user_id' => $this->userId,
            'timestamp' => now()->toIso8601String(),
        ];

        Log::info('HubSpot contact creation job started.', $contextBase);

        $user = User::with(['profile', 'company'])->find($this->userId);

        if (!$user) {
            Log::warning('HubSpot contact creation skipped: user not found.', array_merge($contextBase, [
                'final_status' => 'skipped_user_not_found',
            ]));
            return;
        }

        $context = array_merge($contextBase, [
            'company_id' => $user->company_id,
            'email' => $user->preferred_email ?? $user->email,
            'account_type' => $user->account_type,
            'email_verified' => (bool) $user->email_verified,
            'hubspot_configured' => $hubSpotService->isConfigured(),
        ]);

        Log::info('HubSpot contact creation job loaded user context.', $context);

        // Only sync verified users
        if (!$user->email_verified) {
            Log::info('HubSpot contact creation skipped: user not email-verified.', array_merge($context, [
                'final_status' => 'skipped_not_verified',
            ]));
            return;
        }

        $email = $user->preferred_email;

        if (empty($email)) {
            Log::warning('HubSpot contact creation skipped: user has no email.', array_merge($context, [
                'final_status' => 'skipped_no_email',
            ]));
            return;
        }

        $context['email'] = $email;

        if (!$hubSpotService->isConfigured()) {
            Log::error('HubSpot contact creation failed: missing access token configuration.', array_merge($context, [
                'final_status' => 'failed_not_configured',
            ]));
            return;
        }

        Log::info('HubSpot contact existence check initiated.', $context);
        $exists = $hubSpotService->contactExists($email, $context);

        // If we know the contact exists, do not create it again.
        if ($exists === true) {
            Log::info('HubSpot contact already exists, skipping creation.', array_merge($context, [
                'final_status' => 'skipped_already_exists',
            ]));
            return;
        }

        // If existence is unknown due to API/config issues, log and bail out to avoid duplicates.
        if ($exists === null) {
            Log::warning('HubSpot contact existence unknown, skipping creation to avoid duplicates.', array_merge($context, [
                'final_status' => 'skipped_existence_unknown',
                'reason' => 'contactExists returned null (API failure, timeout, or misconfiguration). Registration is unaffected.',
            ]));
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

        Log::info('HubSpot contact creation payload prepared.', array_merge($context, [
            'payload' => ['properties' => $properties],
        ]));

        $created = $hubSpotService->createContact($properties, $context);

        if (!$created) {
            // Error already logged in service; do not throw to avoid impacting user flow.
            Log::warning('HubSpot contact creation reported failure.', array_merge($context, [
                'final_status' => 'failed',
            ]));
            return;
        }

        Log::info('HubSpot contact creation completed successfully.', array_merge($context, [
            'final_status' => 'success',
        ]));
    }
}
