<?php

namespace App\Services;

use App\Models\Company;
use App\Models\CompanyReferral;
use App\Models\ReferralLink;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;
use RuntimeException;

class ReferralService
{
    private const CODE_LENGTH = 7;
    private const CODE_ALPHABET = 'ABCDEFGHJKLMNPQRSTUVWXYZ23456789';

    /**
     * Get or create the company's active reusable referral link.
     */
    public function getOrCreateActiveLink(Company $company): ReferralLink
    {
        return DB::transaction(function () use ($company) {
            $existing = ReferralLink::query()
                ->where('company_id', $company->id)
                ->active()
                ->lockForUpdate()
                ->first();

            if ($existing) {
                return $existing;
            }

            return ReferralLink::create([
                'company_id' => $company->id,
                'referral_code' => $this->generateUniqueCode(),
                'status' => ReferralLink::STATUS_ACTIVE,
            ]);
        });
    }

    /**
     * Build the public registration URL for a referral code.
     */
    public function buildReferralUrl(string $referralCode): string
    {
        $base = rtrim((string) env('APP_FRONTEND_URL', config('app.url', '')), '/');

        return $base.'/register?ref='.urlencode($referralCode);
    }

    /**
     * Resolve an active referral link by code, or null when invalid/inactive.
     */
    public function findActiveLinkByCode(?string $referralCode): ?ReferralLink
    {
        $code = $this->normalizeCode($referralCode);

        if ($code === '') {
            return null;
        }

        return ReferralLink::query()
            ->active()
            ->where('referral_code', $code)
            ->with('company')
            ->first();
    }

    /**
     * Public validation payload for a referral code.
     *
     * @return array{valid: bool, company?: array{id: int, name: string}, message?: string}
     */
    public function validateReferralCode(?string $referralCode): array
    {
        $link = $this->findActiveLinkByCode($referralCode);

        if (!$link || !$link->company) {
            return [
                'valid' => false,
                'message' => 'Invalid or inactive referral code.',
            ];
        }

        return [
            'valid' => true,
            'company' => [
                'id' => (int) $link->company->id,
                'name' => (string) $link->company->name,
            ],
        ];
    }

    /**
     * Create a company referral record after successful registration.
     *
     * @throws InvalidArgumentException
     */
    public function createReferralForRegistration(
        string $referralCode,
        Company $referredCompany,
        ?User $referrerUser = null
    ): CompanyReferral {
        $link = $this->findActiveLinkByCode($referralCode);

        if (!$link) {
            throw new InvalidArgumentException('Invalid or inactive referral code.');
        }

        return $this->createReferralFromLink($link, $referredCompany, $referrerUser);
    }

    /**
     * Create a referral from a manually selected referring company (no referral code required).
     *
     * @throws InvalidArgumentException
     */
    public function createReferralForCompanyId(
        int $referrerCompanyId,
        Company $referredCompany,
        ?User $referrerUser = null
    ): CompanyReferral {
        $referrerCompany = Company::query()
            ->whereKey($referrerCompanyId)
            ->whereNull('blocked_by_admin_at')
            ->first();

        if (!$referrerCompany) {
            throw new InvalidArgumentException('The selected referring company is invalid.');
        }

        if ((int) $referrerCompany->id === (int) $referredCompany->id) {
            throw new InvalidArgumentException('A company cannot refer itself.');
        }

        $link = $this->getOrCreateActiveLink($referrerCompany);

        return $this->createReferralFromLink($link, $referredCompany, $referrerUser);
    }

    /**
     * @throws InvalidArgumentException
     */
    private function createReferralFromLink(
        ReferralLink $link,
        Company $referredCompany,
        ?User $referrerUser = null
    ): CompanyReferral {
        if ((int) $link->company_id === (int) $referredCompany->id) {
            throw new InvalidArgumentException('A company cannot refer itself.');
        }

        if (CompanyReferral::query()->where('referred_company_id', $referredCompany->id)->exists()) {
            throw new InvalidArgumentException('This company already has a referral relationship.');
        }

        $link->loadMissing('company');

        $resolvedReferrerUserId = null;
        if ($referrerUser && (int) $referrerUser->company_id === (int) $link->company_id) {
            $resolvedReferrerUserId = $referrerUser->id;
        } elseif ($link->company?->default_contact_id) {
            $resolvedReferrerUserId = $link->company->default_contact_id;
        }

        return CompanyReferral::create([
            'referral_link_id' => $link->id,
            'referrer_company_id' => $link->company_id,
            'referred_company_id' => $referredCompany->id,
            'referrer_user_id' => $resolvedReferrerUserId,
            'status' => CompanyReferral::STATUS_REGISTERED,
        ]);
    }

    public function generateUniqueCode(): string
    {
        for ($attempt = 0; $attempt < 20; $attempt++) {
            $code = $this->randomCode();

            if (!ReferralLink::query()->where('referral_code', $code)->exists()) {
                return $code;
            }
        }

        throw new RuntimeException('Unable to generate a unique referral code.');
    }

    private function randomCode(): string
    {
        $alphabet = self::CODE_ALPHABET;
        $max = strlen($alphabet) - 1;
        $code = '';

        for ($i = 0; $i < self::CODE_LENGTH; $i++) {
            $code .= $alphabet[random_int(0, $max)];
        }

        return $code;
    }

    private function normalizeCode(?string $referralCode): string
    {
        return strtoupper(trim((string) $referralCode));
    }
}
