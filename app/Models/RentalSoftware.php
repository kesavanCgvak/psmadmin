<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class RentalSoftware extends Model
{
    use HasFactory;

    // The name of the table associated with the model.
    protected $table = 'rental_softwares';

    // The attributes that are mass assignable.
    protected $fillable = [
        'name',
        'description',
        'version',
        'price',
    ];

    // The attributes that should be hidden for arrays.
    protected $hidden = [
        'created_at',
        'updated_at',
    ];

    // The attributes that should be cast to native types.
    protected $casts = [
        'price' => 'decimal:2',  // Ensure that price is always a decimal with 2 decimal places
    ];

    public function companies()
    {
        return $this->hasMany(Company::class);
    }

}
