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
        'first_name',
        'last_name',
        'birthday',      // stored as string (e.g. MM-DD)
        'user_id',
        'profile_picture',
        'mobile',
        'email',
    ];

    /**
     * Get the full name. Prefers first_name + last_name when set; otherwise falls back to full_name column.
     */
    public function getFullNameAttribute(): string
    {
        $first = $this->attributes['first_name'] ?? '';
        $last = $this->attributes['last_name'] ?? '';
        $computed = trim($first . ' ' . $last);
        if ($computed !== '') {
            return $computed;
        }
        return trim($this->attributes['full_name'] ?? '');
    }

    public function user()
    {
        return $this->belongsTo(User::class);
    }
}
