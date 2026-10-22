<?php

namespace App\Services;

use App\Exceptions\SupportSessionException;
use App\Models\ActivityLog;
use App\Models\Company;
use App\Models\SupportSession;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Database\QueryException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Throwable;
use Tymon\JWTAuth\Exceptions\JWTException;
use Tymon\JWTAuth\Facades\JWTAuth;
use Tymon\JWTAuth\Payload;

class SupportSessionService
{
    public function __construct(private readonly ActivityLogService $activityLogs)
    {
    }

    public function ttlMinutes(): int
    {
        $ttl = (int) config('support_session.ttl_minutes', 30);

        if ($ttl < 5 || $ttl > 120) {
            return 30;
        }

        return $ttl;
    }

    public function isPlatformAdministrator(?User $user): bool
    {
        return $user !== null && $user->role === 'super_admin';
    }

    /**
     * @return array{session: SupportSession, token: string}
     */
    public function start(User $admin, User $target, Request $request): array
    {
        $this->assertCanStart($admin);
        $this->assertEligible($admin, $target);

        try {
            return DB::transaction(function () use ($admin, $target, $request) {
                User::query()->whereKey($target->id)->lockForUpdate()->first();

                $conflict = SupportSession::query()
                    ->where('assumed_user_id', $target->id)
                    ->where('status', SupportSession::STATUS_ACTIVE)
                    ->where('expires_at', '>', now())
                    ->lockForUpdate()
                    ->first();

                if ($conflict && (int) $conflict->admin_user_id !== (int) $admin->id) {
                    throw new SupportSessionException(
                        'conflict',
                        'This user already has an active support session.',
                        409
                    );
                }

                $ownSessions = SupportSession::query()
                    ->where('admin_user_id', $admin->id)
                    ->where('status', SupportSession::STATUS_ACTIVE)
                    ->lockForUpdate()
                    ->get();

                foreach ($ownSessions as $existing) {
                    $this->markEnded($existing, SupportSession::REASON_SUPERSEDED);
                    $this->audit(
                        ActivityLog::ACTION_SUPPORT_ENDED,
                        $existing,
                        ['status' => SupportSession::STATUS_ACTIVE],
                        [
                            'admin_user_id' => $existing->admin_user_id,
                            'assumed_user_id' => $existing->assumed_user_id,
                            'status' => $existing->status,
                            'termination_reason' => $existing->termination_reason,
                        ]
                    );
                }

                $session = new SupportSession([
                    'admin_user_id' => $admin->id,
                    'assumed_user_id' => $target->id,
                    'status' => SupportSession::STATUS_ACTIVE,
                    'started_at' => now(),
                    'expires_at' => now()->addMinutes($this->ttlMinutes()),
                    'ip_address' => $request->ip(),
                    'user_agent' => $this->userAgent($request),
                ]);
                $session->save();

                $token = $this->issueToken($target, $session, $admin);
                $payload = JWTAuth::setToken($token)->getPayload();
                $session->jti = (string) $payload->get('jti');
                $session->expires_at = Carbon::createFromTimestamp((int) $payload->get('exp'));
                $session->save();
                $this->resetJwtState();

                $this->audit(
                    ActivityLog::ACTION_SUPPORT_STARTED,
                    $session,
                    null,
                    [
                        'admin_user_id' => $admin->id,
                        'assumed_user_id' => $target->id,
                        'status' => $session->status,
                        'expires_at' => $session->expires_at?->toIso8601String(),
                    ]
                );

                return ['session' => $session->fresh(), 'token' => $token];
            });
        } catch (SupportSessionException $e) {
            throw $e;
        } catch (QueryException $e) {
            Log::error('Support session could not be stored', [
                'admin_user_id' => $admin->id,
                'assumed_user_id' => $target->id,
                'error' => $e->getMessage(),
            ]);

            throw new SupportSessionException(
                'storage',
                'Unable to start a support session.',
                500
            );
        }
    }

    public function endForAdministrator(User $admin, SupportSession $session): SupportSession
    {
        if (! $this->isPlatformAdministrator($admin)) {
            throw new SupportSessionException(
                'forbidden',
                'You do not have permission to end a support session.',
                403
            );
        }

        if ((int) $session->admin_user_id !== (int) $admin->id) {
            throw new SupportSessionException(
                'ownership',
                'You cannot end this support session.',
                403
            );
        }

        if ($session->status !== SupportSession::STATUS_ACTIVE) {
            throw new SupportSessionException(
                'ended',
                'This support session has already ended.',
                409
            );
        }

        return $this->terminate($session, SupportSession::REASON_ADMIN_ENDED, null);
    }

    public function endForSupportRequest(Request $request): SupportSession
    {
        $session = $this->authenticate($request);
        if (! $session instanceof SupportSession) {
            throw new SupportSessionException(
                'not_support',
                'A support session is required.',
                403
            );
        }

        return $this->terminate($session, SupportSession::REASON_SUPPORT_ENDED, $request->bearerToken());
    }

    /**
     * Validate the bearer token when it is a support credential.
     * Returns null when the request is not a support session.
     */
    public function authenticate(Request $request): ?SupportSession
    {
        return $this->readSupportSession($request);
    }

    /**
     * tymon keeps decoded claims on a process-wide factory. Clear them so a
     * later normal token cannot inherit support-session claims.
     */
    public function clearTokenFactory(): void
    {
        $this->resetJwtState();
    }

    private function resetJwtState(): void
    {
        try {
            $factory = JWTAuth::factory();
            $factory->claims([]);
            $factory->emptyClaims();
            JWTAuth::claims([]);
            JWTAuth::unsetToken();
        } catch (Throwable $e) {
            JWTAuth::unsetToken();
        }
    }

    private function readSupportSession(Request $request): ?SupportSession
    {
        $token = $request->bearerToken();
        if (! is_string($token) || $token === '') {
            return null;
        }

        try {
            $payload = JWTAuth::setToken($token)->getPayload();
        } catch (JWTException $e) {
            JWTAuth::unsetToken();

            return null;
        }

        $tokenUse = $payload->get(SupportSession::CLAIM_USE);
        $jti = (string) ($payload->get('jti') ?? '');

        if ($tokenUse !== SupportSession::TOKEN_USE) {
            $this->rejectStrippedSupportContext($jti, $tokenUse);

            return null;
        }

        return $this->sessionFromPayload($payload, $token);
    }

    public function logBlocked(SupportSession $session, Request $request): void
    {
        Log::warning('Blocked support session action', [
            'session_id' => $session->id,
            'admin_user_id' => $session->admin_user_id,
            'assumed_user_id' => $session->assumed_user_id,
            'method' => $request->getMethod(),
            'path' => $request->path(),
        ]);

        $this->audit(
            ActivityLog::ACTION_SUPPORT_BLOCKED,
            $session,
            null,
            [
                'admin_user_id' => $session->admin_user_id,
                'assumed_user_id' => $session->assumed_user_id,
                'method' => $request->getMethod(),
                'path' => '/'.$request->path(),
            ]
        );
    }

    /**
     * @return array<string, mixed>
     */
    public function present(SupportSession $session, ?string $token = null): array
    {
        $session->loadMissing([
            'assumedUser.profile',
            'assumedUser.company.currency',
            'assumedUser.company.rentalSoftware',
            'assumedUser.company.dateFormat',
            'assumedUser.company.pricingScheme',
        ]);
        $assumed = $session->assumedUser;
        $company = $assumed?->company;
        $ttl = $session->expires_at
            ? max(0, $session->expires_at->getTimestamp() - now()->getTimestamp())
            : 0;

        $data = [
            'session_id' => $session->id,
            'read_only' => true,
            'status' => $session->status,
            'expires_at' => $session->expires_at?->toIso8601String(),
            'expires_in' => $ttl,
            'assumed_user' => [
                'id' => $assumed?->id,
                'username' => $assumed?->username,
                'name' => $assumed?->profile?->full_name ?: $assumed?->username,
                'company' => $company?->name,
                'account_type' => $assumed?->account_type ?: $company?->account_type,
                'company_id' => $assumed?->company_id,
                'is_admin' => (int) ($assumed?->is_admin ?? 0),
                'role' => $assumed?->role,
                'company_profile' => $this->companyProfile($company),
            ],
            'administrator_id' => $session->admin_user_id,
        ];

        if ($token !== null) {
            $data['token_type'] = 'Bearer';
            $data['access_token'] = $token;
        }

        return $data;
    }

    /**
     * Identity the Vue app already loads after a normal login.
     * Secrets, API keys, and payment credentials are omitted.
     *
     * @return array<string, mixed>|null
     */
    private function companyProfile(?Company $company): ?array
    {
        if ($company === null) {
            return null;
        }

        $dateFormat = $company->date_format ?: $company->dateFormat?->format;
        $pricingScheme = $company->pricingScheme?->name ?: $company->pricing_scheme;

        return [
            'id' => $company->id,
            'name' => $company->name,
            'is_open_api_enabled' => strtolower((string) ($company->account_type ?? '')) === 'provider'
                && (bool) ($company->is_open_api_enabled ?? false),
            'description' => $company->description,
            'currency' => $company->currency ? [
                'id' => $company->currency->id,
                'name' => $company->currency->name,
                'code' => $company->currency->code,
                'symbol' => $company->currency->symbol,
            ] : null,
            'rental_software' => $company->rentalSoftware ? [
                'id' => $company->rentalSoftware->id,
                'name' => $company->rentalSoftware->name,
            ] : null,
            'pricing_scheme' => is_string($pricingScheme) ? $pricingScheme : null,
            'date_format' => is_string($dateFormat) ? $dateFormat : null,
            'address_line_1' => $company->address_line_1,
            'address_line_2' => $company->address_line_2,
            'postal_code' => $company->postal_code,
        ];
    }

    private function assertCanStart(User $admin): void
    {
        if (! $this->isPlatformAdministrator($admin)) {
            throw new SupportSessionException(
                'forbidden',
                'You do not have permission to start a support session.',
                403
            );
        }
    }

    private function assertEligible(User $admin, User $target): void
    {
        if ((int) $admin->id === (int) $target->id) {
            throw new SupportSessionException(
                'self',
                'You cannot assume your own identity.',
                422
            );
        }

        if ($target->role === 'super_admin' || (bool) $target->is_blocked) {
            throw new SupportSessionException(
                'ineligible',
                'This account is not eligible for support access.',
                422
            );
        }
    }

    private function sessionFromPayload(Payload $payload, string $token): SupportSession
    {
        $sessionId = (int) $payload->get(SupportSession::CLAIM_ID);
        $adminId = (int) $payload->get(SupportSession::CLAIM_ADMIN);
        $assumedId = (int) $payload->get('sub');
        $jti = (string) $payload->get('jti');

        if ($sessionId < 1 || $adminId < 1 || $assumedId < 1 || $jti === '') {
            throw new SupportSessionException(
                'invalid',
                'Support session is invalid or has ended.',
                401
            );
        }

        try {
            $session = SupportSession::query()->whereKey($sessionId)->first();
        } catch (QueryException $e) {
            Log::error('Support session lookup failed', ['error' => $e->getMessage()]);

            throw new SupportSessionException(
                'storage',
                'Support session is invalid or has ended.',
                401
            );
        }

        if (! $session || (string) $session->jti !== $jti) {
            throw new SupportSessionException(
                'invalid',
                'Support session is invalid or has ended.',
                401
            );
        }

        if ((int) $session->admin_user_id !== $adminId || (int) $session->assumed_user_id !== $assumedId) {
            throw new SupportSessionException(
                'ownership',
                'Support session does not belong to this credential.',
                403
            );
        }

        if ($session->status === SupportSession::STATUS_ACTIVE && $session->expires_at && $session->expires_at->lte(now())) {
            $this->terminate($session, SupportSession::REASON_EXPIRED, $token);

            throw new SupportSessionException(
                'expired',
                'Support session has expired.',
                401
            );
        }

        if ($session->status !== SupportSession::STATUS_ACTIVE) {
            throw new SupportSessionException(
                'ended',
                'Support session is invalid or has ended.',
                401
            );
        }

        return $session;
    }

    private function rejectStrippedSupportContext(string $jti, mixed $tokenUse): void
    {
        if ($tokenUse !== null && $tokenUse !== SupportSession::TOKEN_USE) {
            throw new SupportSessionException(
                'invalid',
                'Support session is invalid or has ended.',
                401
            );
        }

        if ($jti === '') {
            return;
        }

        try {
            $known = SupportSession::query()->where('jti', $jti)->exists();
        } catch (QueryException $e) {
            return;
        }

        if ($known) {
            throw new SupportSessionException(
                'context_missing',
                'Support session is invalid or has ended.',
                401
            );
        }
    }

    private function terminate(SupportSession $session, string $reason, ?string $rawToken): SupportSession
    {
        $changed = false;

        $ended = DB::transaction(function () use ($session, $reason, &$changed) {
            $locked = SupportSession::query()->whereKey($session->id)->lockForUpdate()->first();
            if (! $locked || $locked->status !== SupportSession::STATUS_ACTIVE) {
                return $locked ?? $session;
            }

            $this->markEnded($locked, $reason);
            $changed = true;

            return $locked->fresh() ?? $locked;
        });

        if ($rawToken) {
            $this->blacklist($rawToken);
        }

        if ($changed) {
            $this->audit(
                ActivityLog::ACTION_SUPPORT_ENDED,
                $ended,
                ['status' => SupportSession::STATUS_ACTIVE],
                [
                    'admin_user_id' => $ended->admin_user_id,
                    'assumed_user_id' => $ended->assumed_user_id,
                    'status' => $ended->status,
                    'termination_reason' => $ended->termination_reason,
                ]
            );
        }

        return $ended;
    }

    private function markEnded(SupportSession $session, string $reason): void
    {
        $status = match ($reason) {
            SupportSession::REASON_EXPIRED => SupportSession::STATUS_EXPIRED,
            SupportSession::REASON_REVOKED => SupportSession::STATUS_REVOKED,
            default => SupportSession::STATUS_ENDED,
        };

        $session->status = $status;
        $session->ended_at = now();
        $session->termination_reason = $reason;
        $session->save();
    }

    private function blacklist(string $rawToken): void
    {
        try {
            JWTAuth::setToken($rawToken)->invalidate();
        } catch (Throwable $e) {
            Log::warning('Support token could not be blacklisted', [
                'error' => $e->getMessage(),
            ]);
        } finally {
            JWTAuth::unsetToken();
        }
    }

    private function issueToken(User $assumed, SupportSession $session, User $admin): string
    {
        $factory = JWTAuth::factory();
        $originalTtl = (int) $factory->getTTL();

        try {
            $this->resetJwtState();
            $factory->setTTL($this->ttlMinutes());

            return JWTAuth::claims([
                SupportSession::CLAIM_USE => SupportSession::TOKEN_USE,
                SupportSession::CLAIM_ID => $session->id,
                SupportSession::CLAIM_ADMIN => $admin->id,
            ])->fromUser($assumed);
        } finally {
            $factory->setTTL($originalTtl);
            $this->resetJwtState();
        }
    }

    /**
     * @param  array<string, mixed>|null  $oldValues
     * @param  array<string, mixed>  $newValues
     */
    private function audit(string $action, SupportSession $session, ?array $oldValues, array $newValues): void
    {
        try {
            $companyId = User::query()->whereKey($session->assumed_user_id)->value('company_id');

            $this->activityLogs->record(
                $action,
                ActivityLog::ENTITY_SUPPORT_SESSION,
                (int) $session->id,
                $companyId !== null ? (int) $companyId : null,
                $session->admin_user_id ? (int) $session->admin_user_id : null,
                $oldValues,
                $newValues
            );
        } catch (Throwable $e) {
            Log::warning('Support session activity log failed', [
                'session_id' => $session->id,
                'action' => $action,
                'error' => $e->getMessage(),
            ]);
        }
    }

    private function userAgent(Request $request): ?string
    {
        $agent = $request->userAgent();

        return $agent ? mb_substr($agent, 0, 2000) : null;
    }
}
