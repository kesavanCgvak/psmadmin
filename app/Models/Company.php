<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Company extends Model
{
    protected $fillable = [
        'name',
        'account_type',
        'description',
        'logo',
        'logo_available_for_promotion',
        'logo_promotion_consent_at',
        'logo_promotion_admin_enabled',
        'logo_promotion_sort_order',
        'image1',
        'image2',
        'image3',
        'currency_id',
        'date_format',
        'date_format_id',
        'pricing_scheme',
        'pricing_scheme_id',
        'rental_software_id',
        'region_id',
        'country_id',
        'city_id',
        'state_id',
        'default_contact_id',
        'address_line_1',
        'address_line_2',
        'search_priority',
        'postal_code',
        'latitude',
        'longitude',
        'hide_from_gear_finder',
        'subscription_mode',
        'is_open_api_enabled',
        'blocked_by_admin_at',
        'rating_override',
        'rating_override_set_by',
        'rating_override_reason',
        'rating_override_set_at',
    ];

    protected $casts = [
        'blocked_by_admin_at' => 'datetime',
        'logo_available_for_promotion' => 'boolean',
        'logo_promotion_consent_at' => 'datetime',
        'logo_promotion_admin_enabled' => 'boolean',
        'logo_promotion_sort_order' => 'integer',
        'is_open_api_enabled' => 'boolean',
        'rating_override' => 'float',
        'rating_override_set_at' => 'datetime',
    ];

    public function users()
    {
        return $this->hasMany(User::class);
    }

    public function equipments()
    {
        return $this->hasMany(Equipment::class);
    }

    /**
     * The default contact for the company.
     *
     * @return \Illuminate\Database\Eloquent\Relations\BelongsTo
     */
    public function defaultContact()
    {
        return $this->belongsTo(User::class, 'default_contact_id');
    }

    /**
     * Get the address of the company.
     *
     * @return string
     */
    public function getAddressAttribute()
    {
        return trim("{$this->address_line_1} {$this->address_line_2} {$this->city}, {$this->state}, {$this->country}, {$this->postal_code}");
    }

    public function rentalSoftware()
    {
        return $this->belongsTo(RentalSoftware::class);
    }

    public function currency()
    {
        return $this->belongsTo(Currency::class);
    }

    public function dateFormat()
    {
        return $this->belongsTo(DateFormat::class);
    }

    public function pricingScheme()
    {
        return $this->belongsTo(PricingScheme::class);
    }

    public function region()
    {
        return $this->belongsTo(Region::class);

    }

    public function country()
    {
        return $this->belongsTo(Country::class);
    }

    public function city()
    {
        return $this->belongsTo(City::class);
    }

    public function defaultContactProfile()
    {
        return $this->hasOne(UserProfile::class, 'user_id', 'default_contact_id')
            ->select('user_id', 'first_name', 'last_name', 'full_name', 'email', 'mobile');
    }

    public function supplyJobs()
    {
        return $this->hasMany(SupplyJob::class, 'provider_id');
    }

    public function getcountry()
    {
        return $this->belongsTo('App\Models\Country', 'country_id', 'id');
    }

    public function getcity()
    {
        return $this->belongsTo('App\Models\City', 'city_id', 'id');
    }

    public function getregion()
    {
        return $this->belongsTo('App\Models\Region', 'region_id', 'id');
    }

    public function state()
    {
        return $this->belongsTo(StateProvince::class, 'state_id');
    }

    public function getState()
    {
        return $this->belongsTo('App\Models\StateProvince', 'state_id', 'id');
    }
    public function getDefaultcontact()
    {
        return $this->belongsTo('App\Models\UserProfile', 'default_contact_id', 'user_id');
    }

    public function ratings()
    {
        return $this->hasMany(CompanyRating::class);
    }

    public function blocks()
    {
        return $this->hasMany(CompanyBlock::class);
    }

    /**
     * Average rating accessor (calculated from related ratings).
     */
    public function getAverageRatingAttribute()
    {
        return $this->ratings()->avg('rating') ?? 0;
    }

    /**
     * Whether this company is blocked by admin (e.g. due to low ratings).
     */
    public function isBlockedByAdmin(): bool
    {
        return $this->blocked_by_admin_at !== null;
    }

    /**
     * Check if a given user has blocked this company.
     */
    public function isBlockedByUser($userId)
    {
        return $this->blocks()->where('user_id', $userId)->exists();
    }

    /**
     * Get the provider subscription for this company
     */
    public function subscription()
    {
        return $this->hasOne(Subscription::class, 'company_id')
            ->where('account_type', 'provider')
            ->latestOfMany();
    }

    public function trialIncentiveGrants()
    {
        return $this->hasMany(SubscriptionTrialIncentiveGrant::class);
    }

    /**
     * Reusable referral links owned by this company.
     */
    public function referralLinks()
    {
        return $this->hasMany(ReferralLink::class);
    }

    /**
     * Active reusable referral link for this company (at most one in normal use).
     */
    public function activeReferralLink()
    {
        return $this->hasOne(ReferralLink::class)->where('status', ReferralLink::STATUS_ACTIVE);
    }

    /**
     * Referral relationship where this company was referred by another company.
     */
    public function referralReceived()
    {
        return $this->hasOne(CompanyReferral::class, 'referred_company_id');
    }

    /**
     * Referrals this company has made (companies that registered via its link).
     */
    public function referralsMade()
    {
        return $this->hasMany(CompanyReferral::class, 'referrer_company_id');
    }

    /**
     * Get all subscriptions for this company (for reporting/analytics)
     */
    public function subscriptions()
    {
        return $this->hasMany(Subscription::class, 'company_id');
    }

    /**
     * Check if company has an active subscription
     */
    public function hasActiveSubscription(): bool
    {
        return $this->subscription && $this->subscription->isActive();
    }

    /**
     * Get the provider owner (first admin user)
     */
    public function providerOwner()
    {
        return $this->users()->where('is_admin', 1)->first();
    }

    /**
     * Get additional users (excluding provider owner)
     */
    public function additionalUsers()
    {
        $providerId = $this->providerOwner()?->id;
        return $this->users()
            ->when($providerId, fn($q) => $q->where('id', '!=', $providerId))
            ->get();
    }

    /**
     * Get count of additional users (excluding provider owner)
     */
    public function additionalUsersCount(): int
    {
        $providerId = $this->providerOwner()?->id;
        return $this->users()
            ->when($providerId, fn($q) => $q->where('id', '!=', $providerId))
            ->count();
    }

    /**
     * Check if company can add more users (uses configurable limit)
     */
    public function canAddMoreUsers(): bool
    {
        $limit = \App\Models\Setting::getCompanyUserLimit();
        return $this->users()->count() < $limit;
    }

    /**
     * Get current user count for the company
     */
    public function getUserCount(): int
    {
        return $this->users()->count();
    }

    /**
     * Get maximum user limit for companies
     */
    public function getMaxUserLimit(): int
    {
        return \App\Models\Setting::getCompanyUserLimit();
    }

    /**
     * Get the company's integration configurations.
     */
    public function integrations()
    {
        return $this->hasMany(CompanyIntegration::class);
    }

    /**
     * Apply promotional logo consent. Returns false when enabling without an uploaded logo.
     */
    public function applyLogoPromotionConsent(bool $enabled): bool
    {
        if ($enabled && empty($this->logo)) {
            return false;
        }

        $this->logo_available_for_promotion = $enabled;
        $this->logo_promotion_consent_at = $enabled ? now() : null;

        if ($enabled && (int) $this->logo_promotion_sort_order <= 0) {
            $maxSortOrder = (int) static::query()->max('logo_promotion_sort_order');
            $this->logo_promotion_sort_order = $maxSortOrder + 1;
        }

        $this->save();

        return true;
    }

    /**
     * Revoke promotional logo consent (e.g. when the logo is deleted).
     */
    public function revokeLogoPromotionConsent(): void
    {
        if (!$this->logo_available_for_promotion && $this->logo_promotion_consent_at === null) {
            return;
        }

        $this->logo_available_for_promotion = false;
        $this->logo_promotion_consent_at = null;
        $this->save();
    }

    /**
     * Admin-only toggle for promotional logo use (does not change user consent).
     */
    public function applyLogoPromotionAdminStatus(bool $enabled): bool
    {
        if ($enabled && empty($this->logo)) {
            return false;
        }

        $this->logo_promotion_admin_enabled = $enabled;
        $this->save();

        return true;
    }

    /**
     * Whether the logo is available for promotional use (user consent + admin approval + logo exists).
     */
    public function isLogoPromotionActive(): bool
    {
        return $this->isProviderCompany()
            && !empty($this->logo)
            && (bool) $this->logo_available_for_promotion
            && (bool) $this->logo_promotion_admin_enabled;
    }

    /**
     * Whether this company is a provider account.
     */
    public function isProviderCompany(): bool
    {
        return strtolower((string) ($this->account_type ?? '')) === 'provider';
    }

    /**
     * Scope to provider companies only.
     */
    public function scopeProviders($query)
    {
        return $query->whereRaw('LOWER(account_type) = ?', ['provider']);
    }

    /**
     * Scope for logos approved by both the company and admin.
     */
    public function scopePromotionalLogosActive($query)
    {
        return $query
            ->providers()
            ->whereNotNull('logo')
            ->where('logo', '!=', '')
            ->where('logo_available_for_promotion', true)
            ->where('logo_promotion_admin_enabled', true)
            ->orderBy('logo_promotion_sort_order')
            ->orderBy('name');
    }

}
