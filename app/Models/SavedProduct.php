<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class SavedProduct extends Model
{
    use HasFactory;

    // Define the table name (optional if the table name follows Laravel's naming conventions)
    protected $table = 'saved_products';

    // Define the fillable fields
    protected $fillable = [
        'user_id',   // The ID of the user who saved the product
        'product_id', // The ID of the saved product
        'quantity',   // The quantity of the product saved
    ];

    /**
     * Define the relationship to the User model.
     */
    public function user()
    {
        return $this->belongsTo(User::class);
    }

    /**
     * Define the relationship to the Product model.
     */
    public function product()
    {
        return $this->belongsTo(Product::class);
    }
}
