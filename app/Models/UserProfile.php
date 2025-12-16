<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;

class UserProfile extends Model
{
    use HasFactory;

    protected $table = 'user_profiles';

    protected $fillable = [
        'full_name',
        'birthday',
        'user_id',
        'profile_picture',
        'mobile',
        'email',
    ];

    protected $casts = [
        'birthday' => 'date',
    ];

    public function user()
    {
        return $this->belongsTo(User::class);
    }
}
