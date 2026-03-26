<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Maps a rental request (rental_jobs) to a Flex quote per provider company.
 */
class RentalRequestProviderQuote extends Model
{
    protected $table = 'rental_request_provider_quotes';

    protected $fillable = [
        'rental_request_id',
        'provider_id',
        'supply_job_id',
        'flex_quote_id',
        'flex_quote_number',
        'status',
    ];

    public function rentalRequest(): BelongsTo
    {
        return $this->belongsTo(RentalJob::class, 'rental_request_id');
    }

    public function provider(): BelongsTo
    {
        return $this->belongsTo(Company::class, 'provider_id');
    }

    public function supplyJob(): BelongsTo
    {
        return $this->belongsTo(SupplyJob::class, 'supply_job_id');
    }
}
