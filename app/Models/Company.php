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
        'image1',
        'image2',
        'image3',
        'currency_id',
        'date_format',
        'pricing_scheme',
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
            ->select('user_id', 'full_name', 'email', 'mobile');
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
     * Check if a given user has blocked this company.
     */
    public function isBlockedByUser($userId)
    {
        return $this->blocks()->where('user_id', $userId)->exists();
    }


}
