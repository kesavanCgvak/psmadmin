<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class FlexSalesQuoteSyncLog extends Model
{
    protected $table = 'flex_sales_quote_sync_logs';

    protected $fillable = [
        'rental_job_id',
        'supply_job_id',
        'provider_company_id',
        'status',
        'flex_client_id',
        'flex_sales_quote_id',
        'flex_sales_quote_number',
        'products_attached',
        'products_missing',
        'error_message',
        'steps',
    ];

    protected $casts = [
        'products_attached' => 'array',
        'products_missing' => 'array',
        'steps' => 'array',
    ];

    public function rentalJob(): BelongsTo
    {
        return $this->belongsTo(RentalJob::class, 'rental_job_id');
    }

    public function supplyJob(): BelongsTo
    {
        return $this->belongsTo(SupplyJob::class, 'supply_job_id');
    }

    public function providerCompany(): BelongsTo
    {
        return $this->belongsTo(Company::class, 'provider_company_id');
    }
}
