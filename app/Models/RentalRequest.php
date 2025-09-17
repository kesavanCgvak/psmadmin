<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Concerns\HasUuids;


class RentalRequest extends Model
{
    use HasUuids;

    protected $fillable = ['id', 'searchrequest', 'user_id', 'company_id', 'props'];
    protected $casts = ['props' => 'array'];

    public function user()
    {
        return $this->belongsTo(User::class);
    }
    public function company()
    {
        return $this->belongsTo(Company::class);
    }
}
