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
use Tymon\JWTAuth\Contracts\Providers\Storage as JwtStorage;
use Tymon\JWTAuth\Exceptions\JWTException;
use Tymon\JWTAuth\Exceptions\TokenBlacklistedException;
use Tymon\JWTAuth\Facades\JWTAuth;
use Tymon\JWTAuth\Payload;
use Tymon\JWTAuth\Token;

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

    public function handshakeSeconds(): int
    {
        $seconds = (int) config('support_session.handshake_seconds', 90);

        if ($seconds < 15 || $seconds > 180) {
            return 90;
        }

        return $seconds;
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

        try {
            $result = DB::transaction(function () use ($admin, $target, $request) {
                $open = SupportSession::query()
                    ->where(function ($query) use ($admin, $target) {
                        $query->where('assumed_user_id', $target->id)
                            ->orWhere('admin_user_id', $admin->id);
                    })
                    ->whereIn('status', [SupportSession::STATUS_ACTIVE, SupportSession::STATUS_PENDING])
                    ->orderBy('id')
                    ->lockForUpdate()
                    ->get();

                $superseded = [];

                foreach ($open as $existing) {
                    if ($this->isStale($existing)) {
                        $previous = $existing->status;
                        $this->markEnded($existing, $this->staleReason($existing));
                        $this->auditEnded($existing, $previous);
                        $superseded[] = $existing;

                        continue;
                    }

                    if ((int) $existing->admin_user_id === (int) $admin->id) {
                        $previous = $existing->status;
                        $this->markEnded($existing, SupportSession::REASON_SUPERSEDED);
                        $this->auditEnded($existing, $previous);
                        $superseded[] = $existing;

                        continue;
                    }

                    throw new SupportSessionException(
                        'conflict',
                        'This user already has an active support session.',
                        409
                    );
                }

                $session = new SupportSession([
                    'admin_user_id' => $admin->id,
                    'assumed_user_id' => $target->id,
                    'status' => SupportSession::STATUS_PENDING,
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

                return [
                    'session' => $session->fresh(),
                    'token' => $token,
                    'superseded' => $superseded,
                ];
            });
        } catch (SupportSessionException $e) {
            throw $e;
        } catch (JWTException $e) {
            Log::error('Support session credential could not be issued', [
                'admin_user_id' => $admin->id,
                'assumed_user_id' => $target->id,
                'error' => $e->getMessage(),
            ]);

            throw new SupportSessionException(
                'token',
                'Unable to start a support session.',
                500
            );
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

        foreach ($result['superseded'] as $previous) {
            $this->blacklistSession($previous);
        }

        return ['session' => $result['session'], 'token' => $result['token']];
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

        if (! in_array($session->status, [SupportSession::STATUS_ACTIVE, SupportSession::STATUS_PENDING], true)) {
            $this->blacklistSession($session);

            return $session;
        }

        return $this->terminate($session, SupportSession::REASON_ADMIN_ENDED, null);
    }

    public function endForSupportRequest(Request $request): SupportSession
    {
        try {
            $session = $this->authenticate($request);
        } catch (SupportSessionException $e) {
            $finished = $this->finishedSessionForToken($request);
            if ($finished instanceof SupportSession) {
                $this->blacklistSession($finished);

                return $finished;
            }

            throw $e;
        }

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
        } catch (TokenBlacklistedException $e) {
            $payload = $this->uncheckedPayload($token);
            JWTAuth::unsetToken();

            if ($payload instanceof Payload && $payload->get(SupportSession::CLAIM_USE) === SupportSession::TOKEN_USE) {
                throw new SupportSessionException(
                    'revoked',
                    'Support session is invalid or has ended.',
                    401
                );
            }

            return null;
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
            'handshake_expires_at' => $session->status === SupportSession::STATUS_PENDING
                ? $this->handshakeDeadline($session)?->toIso8601String()
                : null,
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

        if ($session->status === SupportSession::STATUS_PENDING && ! $this->isStale($session)) {
            $session = $this->claimActive($session);
        }

        if ($this->isStale($session)) {
            $this->terminate($session, $this->staleReason($session), $token);

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
            if (! $locked || ! in_array($locked->status, [SupportSession::STATUS_ACTIVE, SupportSession::STATUS_PENDING], true)) {
                return $locked ?? $session;
            }

            $this->markEnded($locked, $reason);
            $changed = true;

            return $locked->fresh() ?? $locked;
        });

        $this->blacklistSession($ended);
        if ($rawToken) {
            $this->blacklist($rawToken);
        }

        if ($changed) {
            $previous = $session->status;
            $this->auditEnded($ended, $previous);
        }

        return $ended;
    }

    private function claimActive(SupportSession $session): SupportSession
    {
        return DB::transaction(function () use ($session) {
            $locked = SupportSession::query()->whereKey($session->id)->lockForUpdate()->first();
            if (! $locked || $locked->status !== SupportSession::STATUS_PENDING || $this->isStale($locked)) {
                return $locked ?? $session;
            }

            $locked->status = SupportSession::STATUS_ACTIVE;
            $locked->save();

            $this->audit(
                ActivityLog::ACTION_SUPPORT_ACTIVATED,
                $locked,
                ['status' => SupportSession::STATUS_PENDING],
                [
                    'admin_user_id' => $locked->admin_user_id,
                    'assumed_user_id' => $locked->assumed_user_id,
                    'status' => $locked->status,
                ]
            );

            return $locked;
        });
    }

    private function isStale(SupportSession $session): bool
    {
        if (! in_array($session->status, [SupportSession::STATUS_ACTIVE, SupportSession::STATUS_PENDING], true)) {
            return false;
        }

        if ($session->expires_at && $session->expires_at->lte(now())) {
            return true;
        }

        return $session->status === SupportSession::STATUS_PENDING && $this->handshakeExpired($session);
    }

    private function staleReason(SupportSession $session): string
    {
        if ($session->expires_at && $session->expires_at->lte(now())) {
            return SupportSession::REASON_EXPIRED;
        }

        return SupportSession::REASON_HANDSHAKE;
    }

    private function handshakeExpired(SupportSession $session): bool
    {
        $deadline = $this->handshakeDeadline($session);

        return $deadline === null || $deadline->lte(now());
    }

    private function handshakeDeadline(SupportSession $session): ?Carbon
    {
        $started = $session->started_at ?? $session->created_at;

        return $started ? $started->copy()->addSeconds($this->handshakeSeconds()) : null;
    }

    private function auditEnded(SupportSession $session, string $previousStatus): void
    {
        $this->audit(
            ActivityLog::ACTION_SUPPORT_ENDED,
            $session,
            ['status' => $previousStatus],
            [
                'admin_user_id' => $session->admin_user_id,
                'assumed_user_id' => $session->assumed_user_id,
                'status' => $session->status,
                'termination_reason' => $session->termination_reason,
            ]
        );
    }

    private function blacklistSession(SupportSession $session): void
    {
        $jti = (string) ($session->jti ?? '');
        if ($jti === '') {
            return;
        }

        try {
            $storage = app(JwtStorage::class);
            $minutes = max($this->ttlMinutes() + 1, (int) config('jwt.refresh_ttl', 20160));
            $storage->add($jti, ['valid_until' => 0], $minutes);
        } catch (Throwable $e) {
            Log::warning('Support token could not be blacklisted', [
                'session_id' => $session->id,
                'error' => $e->getMessage(),
            ]);
        } finally {
            $this->resetJwtState();
        }
    }

    private function uncheckedPayload(string $token): ?Payload
    {
        try {
            return app('tymon.jwt.manager')->decode(new Token($token), false);
        } catch (JWTException $e) {
            return null;
        } finally {
            $this->resetJwtState();
        }
    }

    private function finishedSessionForToken(Request $request): ?SupportSession
    {
        $token = $request->bearerToken();
        if (! is_string($token) || $token === '') {
            return null;
        }

        $payload = $this->uncheckedPayload($token);
        $this->resetJwtState();
        if (! $payload instanceof Payload || $payload->get(SupportSession::CLAIM_USE) !== SupportSession::TOKEN_USE) {
            return null;
        }

        $session = SupportSession::query()->whereKey((int) $payload->get(SupportSession::CLAIM_ID))->first();
        if (! $session || (string) $session->jti !== (string) $payload->get('jti')) {
            return null;
        }

        if ((int) $session->assumed_user_id !== (int) $payload->get('sub')) {
            return null;
        }

        if (in_array($session->status, [SupportSession::STATUS_ACTIVE, SupportSession::STATUS_PENDING], true) && ! $this->isStale($session)) {
            return null;
        }

        return $session;
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
