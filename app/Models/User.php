<?php

namespace App\Models;

// use Illuminate\Contracts\Auth\MustVerifyEmail;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Illuminate\Contracts\Auth\MustVerifyEmail;
use Tymon\JWTAuth\Contracts\JWTSubject;
use App\Notifications\CustomResetPassword;
use App\Notifications\CustomEmailVerification;
use App\Models\UserProfile;
use App\Models\Company;
use Filament\Panel;
use Illuminate\Support\Facades\Log;
use App\Models\RentalJob;
use App\Models\SupplyJob;
use App\Models\RentalJobComment;



class User extends Authenticatable implements JWTSubject
{
    /** @use HasFactory<\Database\Factories\UserFactory> */
    use  HasFactory, Notifiable;

    /**
     * The attributes that are mass assignable.
     *
     * @var list<string>
     */
    protected $fillable = [
        'account_type',
        'username',
        'email',
        'password',
        'role',
        'company_id',
        'is_company_default_contact',
        'is_admin',
        'email_verified',
        'email_verified_at',
        'is_blocked',
        'token'
    ];


    /**
     * The attributes that should be hidden for serialization.
     *
     * @var list<string>
     */
    protected $hidden = [
        'password',
        'remember_token',
    ];

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'password' => 'hashed',
        ];
    }

    public function profile()
    {
        return $this->hasOne(UserProfile::class);
    }

    public function company()
    {
        return $this->belongsTo(Company::class);
    }

    public function sendPasswordResetNotification($token)
    {
        $this->notify(new CustomResetPassword($token));
    }

    /**
     * Send the email verification notification.
     *
     * @return void
     */
    public function sendEmailVerificationNotification()
    {
        $userEmail = $this->getEmailForVerification();
        $userName = $this->profile ? $this->profile->full_name : $this->username;

        // Log the email verification notification trigger
        Log::info('Email verification notification triggered', [
            'user_id' => $this->getKey(),
            'user_email' => $userEmail,
            'user_name' => $userName,
            'account_type' => $this->account_type,
            'company_id' => $this->company_id,
            'timestamp' => now()->toISOString(),
        ]);

        try {
            $this->notify(new CustomEmailVerification);

            // Log successful notification dispatch
            Log::info('Email verification notification dispatched successfully', [
                'user_id' => $this->getKey(),
                'user_email' => $userEmail,
                'user_name' => $userName,
            ]);

        } catch (\Exception $e) {
            // Log error in notification dispatch
            Log::error('Failed to dispatch email verification notification', [
                'user_id' => $this->getKey(),
                'user_email' => $userEmail,
                'user_name' => $userName,
                'error' => $e->getMessage(),
                'error_trace' => $e->getTraceAsString(),
            ]);

            throw $e;
        }
    }

    /**
     * Get the name of the unique identifier for the user.
     *
     * @return string
     */
    public function getAuthIdentifierName()
    {
        return 'id';
    }

    /**
     * Get the user's display name for Filament.
     *
     * @return string
     */
    public function getName(): string
    {
        // Ensure we always return a string
        $name = $this->username ?? 'User ' . ($this->id ?? 'Unknown');

        return (string) $name;
    }

    /**
     * Get the user's email from profile.
     *
     * @return string|null
     */
    public function getEmail(): ?string
    {
        return $this->profile?->email;
    }

    /**
     * Get the username, ensuring it's never null.
     *
     * @return string
     */
    public function getUsername(): string
    {
        return $this->username ?? 'user_' . $this->id;
    }

    /**
     * Get the email address that should be used for verification.
     *
     * @return string
     */
    public function getEmailForVerification()
    {
        return $this->profile?->email;
    }

    public function rentalJobs()
    {
        return $this->hasMany(RentalJob::class); // Jobs created by this user
    }

    public function supplyJobs()
    {
        return $this->hasMany(SupplyJob::class, 'provider_id'); // Jobs where user is provider
    }

    public function comments()
    {
        return $this->hasMany(RentalJobComment::class, 'sender_id');
    }

    /**
     * Get the identifier that will be stored in the subject claim of the JWT.
     */
    public function getJWTIdentifier()
    {
        return $this->getKey();
    }

    /**
     * Return a key-value array, containing any custom claims to be added to JWT.
     */
    public function getJWTCustomClaims()
    {
        return [];
    }
}
