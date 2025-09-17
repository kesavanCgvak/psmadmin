<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Factories\HasFactory;

class EquipmentImage extends Model
{
    use HasFactory;

    protected $fillable = ['equipment_id', 'image_path'];

    public function equipment()
    {
        return $this->belongsTo(Equipment::class);
    }
}
