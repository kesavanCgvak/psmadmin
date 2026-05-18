<?php

namespace App\Http\Controllers\Api;

use App\Helpers\EmailHelper;
use App\Http\Controllers\Controller;
use App\Models\ProviderApiKey;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Str;
use Tymon\JWTAuth\Facades\JWTAuth;

class ProviderApiKeyController extends Controller
{
    public function index(): JsonResponse
    {
        try {
            $user = JWTAuth::parseToken()->authenticate();
            if (!$this->isProviderUser($user)) {
                return response()->json([
                    'success' => false,
                    'message' => 'Only provider users can manage API keys.',
                ], 403);
            }

            $keys = ProviderApiKey::where('provider_user_id', $user->id)
                ->orderByDesc('created_at')
                ->get()
                ->map(function (ProviderApiKey $key) {
                    return [
                        'id' => $key->id,
                        'name' => $key->name,
                        'key_prefix' => $key->key_prefix,
                        'is_active' => $key->is_active,
                        'created_at' => $key->created_at,
                        'last_used_at' => $key->last_used_at,
                        'expires_at' => $key->expires_at,
                        'revoked_at' => $key->revoked_at,
                    ];
                });

            return response()->json([
                'success' => true,
                'data' => $keys,
            ]);
        } catch (\Exception $e) {
            Log::error('Provider API key listing failed', [
                'error' => $e->getMessage(),
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Unable to fetch API keys.',
            ], 500);
        }
    }

    /**
     * Provider user asks admin to enable Open API access (email to admin inbox).
     */
    public function requestAccess(Request $request): JsonResponse
    {
        $messageText = trim((string) $request->input('message', ''));

        $validator = Validator::make(
            ['message' => $messageText],
            [
                'message' => 'required|string|min:1|max:5000',
            ],
            [
                'message.required' => 'A message is required.',
                'message.min' => 'Message cannot be empty.',
            ]
        );

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'message' => 'Validation failed.',
                'errors' => $validator->errors(),
            ], 422);
        }

        try {
            $user = JWTAuth::parseToken()->authenticate();
            if (!$this->isProviderUser($user)) {
                return response()->json([
                    'success' => false,
                    'message' => 'Only provider company users can request Open API access.',
                ], 403);
            }

            $user->loadMissing(['profile', 'company']);
            $company = $user->company;
            $profile = $user->profile;

            $adminRecipients = $this->adminNotificationRecipients();
            if (empty($adminRecipients)) {
                Log::error('Open API access request: no admin notification addresses configured', [
                    'user_id' => $user->id,
                ]);

                return response()->json([
                    'success' => false,
                    'message' => 'Unable to submit request: admin notification email is not configured.',
                ], 503);
            }

            $emailData = [
                'company_id' => $company->id,
                'company_name' => $company->name,
                'is_open_api_enabled' => (bool) ($company->is_open_api_enabled ?? false),
                'user_id' => $user->id,
                'username' => $user->username,
                'full_name' => $profile?->full_name ?? $user->username,
                'user_email' => $user->preferred_email ?? $user->email ?? ($profile?->email ?? ''),
                'mobile' => $profile?->mobile ?? 'N/A',
                'message' => $messageText,
                'submitted_at' => now()->timezone(config('app.timezone'))->format('M d, Y h:i A T'),
            ];

            $emailSent = false;
            try {
                $emailSent = EmailHelper::send('provider-api-key-access-request', $emailData, function ($message) use ($adminRecipients, $emailData) {
                    $message->to($adminRecipients);
                    $message->from(config('mail.from.address'), config('mail.from.name'));
                    $message->subject('Open API access request: '.$emailData['company_name']);
                });
            } catch (\Throwable $e) {
                Log::error('Open API access request email failed', [
                    'user_id' => $user->id,
                    'company_id' => $company->id,
                    'error' => $e->getMessage(),
                ]);
            }

            if (!$emailSent) {
                Log::warning('Open API access request: email not sent (disabled template or send failure)', [
                    'user_id' => $user->id,
                    'company_id' => $company->id,
                ]);
            }

            Log::info('Open API access request submitted', [
                'user_id' => $user->id,
                'company_id' => $company->id,
                'email_sent' => $emailSent,
            ]);

            return response()->json([
                'success' => true,
                'message' => $emailSent
                    ? 'Your request has been sent to the administrator.'
                    : 'Your request was received, but the notification email could not be sent. Please contact support.',
                'data' => [
                    'email_sent' => $emailSent,
                ],
            ], 200);
        } catch (\Exception $e) {
            Log::error('Open API access request failed', [
                'error' => $e->getMessage(),
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Unable to submit request.',
            ], 500);
        }
    }

    public function generate(Request $request): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'name' => 'nullable|string|max:100',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'message' => 'Validation failed.',
                'errors' => $validator->errors(),
            ], 422);
        }

        try {
            $user = JWTAuth::parseToken()->authenticate();
            if (!$this->isProviderUser($user)) {
                return response()->json([
                    'success' => false,
                    'message' => 'Only provider users can generate API keys.',
                ], 403);
            }

            $plainApiKey = 'psm_pk_' . Str::random(48);
            $hash = hash('sha256', $plainApiKey);
            $keyPrefix = substr($plainApiKey, 0, 14);
            $name = $request->input('name') ?: 'Default key';

            DB::transaction(function () use ($user, $hash, $keyPrefix, $name, $plainApiKey) {
                ProviderApiKey::where('provider_user_id', $user->id)
                    ->where('is_active', true)
                    ->update([
                        'is_active' => false,
                        'revoked_at' => now(),
                    ]);

                ProviderApiKey::create([
                    'provider_user_id' => $user->id,
                    'name' => $name,
                    'key_prefix' => $keyPrefix,
                    'key_hash' => $hash,
                    'encrypted_key' => Crypt::encryptString($plainApiKey),
                    'is_active' => true,
                ]);
            });

            return response()->json([
                'success' => true,
                'message' => 'API key generated successfully. Save it now, it will not be shown again.',
                'data' => [
                    'api_key' => $plainApiKey,
                    'key_prefix' => $keyPrefix,
                ],
            ], 201);
        } catch (\Exception $e) {
            Log::error('Provider API key generation failed', [
                'error' => $e->getMessage(),
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Unable to generate API key.',
            ], 500);
        }
    }

    public function revoke(int $id): JsonResponse
    {
        try {
            $user = JWTAuth::parseToken()->authenticate();
            if (!$this->isProviderUser($user)) {
                return response()->json([
                    'success' => false,
                    'message' => 'Only provider users can revoke API keys.',
                ], 403);
            }

            $apiKey = ProviderApiKey::where('provider_user_id', $user->id)->find($id);
            if (!$apiKey) {
                return response()->json([
                    'success' => false,
                    'message' => 'API key not found.',
                ], 404);
            }

            if (!$apiKey->is_active) {
                return response()->json([
                    'success' => true,
                    'message' => 'API key is already inactive.',
                ]);
            }

            $apiKey->update([
                'is_active' => false,
                'revoked_at' => now(),
            ]);

            return response()->json([
                'success' => true,
                'message' => 'API key revoked successfully.',
            ]);
        } catch (\Exception $e) {
            Log::error('Provider API key revoke failed', [
                'error' => $e->getMessage(),
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Unable to revoke API key.',
            ], 500);
        }
    }

    public function reveal(int $id): JsonResponse
    {
        try {
            $user = JWTAuth::parseToken()->authenticate();
            if (!$this->isProviderUser($user)) {
                return response()->json([
                    'success' => false,
                    'message' => 'Only provider users can reveal API keys.',
                ], 403);
            }

            $apiKey = ProviderApiKey::where('provider_user_id', $user->id)->find($id);
            if (!$apiKey) {
                return response()->json([
                    'success' => false,
                    'message' => 'API key not found.',
                ], 404);
            }

            if (empty($apiKey->encrypted_key)) {
                return response()->json([
                    'success' => false,
                    'message' => 'Full key is not available for this record. Please generate a new key.',
                ], 422);
            }

            return response()->json([
                'success' => true,
                'message' => 'API key fetched successfully.',
                'data' => [
                    'id' => $apiKey->id,
                    'key_prefix' => $apiKey->key_prefix,
                    'api_key' => Crypt::decryptString($apiKey->encrypted_key),
                ],
            ]);
        } catch (\Exception $e) {
            Log::error('Provider API key reveal failed', [
                'error' => $e->getMessage(),
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Unable to reveal API key.',
            ], 500);
        }
    }

    private function isProviderUser($user): bool
    {
        if (!$user) {
            return false;
        }

        $user->loadMissing('company');
        if (!$user->company) {
            return false;
        }

        $companyAccountType = strtolower((string) ($user->company->account_type ?? ''));
        if ($companyAccountType !== 'provider') {
            return false;
        }

        $userAccountType = strtolower((string) ($user->account_type ?? ''));

        return $userAccountType === 'provider';
    }

    /**
     * Admin addresses for registration-style notifications (see config/mail.php).
     *
     * @return list<string>
     */
    private function adminNotificationRecipients(): array
    {
        $raw = config('mail.to.addresses', []);
        $list = is_array($raw) ? $raw : [$raw];
        $emails = array_values(array_filter(array_map(static function ($v) {
            return is_string($v) ? trim($v) : '';
        }, $list)));

        if ($emails !== []) {
            return $emails;
        }

        $admin = config('mail.admin.address');
        if (is_string($admin) && trim($admin) !== '') {
            return [trim($admin)];
        }

        $from = config('mail.from.address');
        if (is_string($from) && trim($from) !== '') {
            return [trim($from)];
        }

        return [];
    }
}

