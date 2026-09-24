<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

class ChatConversation extends Model
{
    protected $fillable = [
        'company_a_id',
        'company_b_id',
        'created_by_user_id',
        'rental_job_id',
        'pair_key',
    ];

    public function companyA(): BelongsTo
    {
        return $this->belongsTo(Company::class, 'company_a_id');
    }

    public function companyB(): BelongsTo
    {
        return $this->belongsTo(Company::class, 'company_b_id');
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by_user_id');
    }

    public function rentalJob(): BelongsTo
    {
        return $this->belongsTo(RentalJob::class, 'rental_job_id');
    }

    public function messages(): HasMany
    {
        return $this->hasMany(ChatMessage::class, 'conversation_id');
    }

    public function userStates(): HasMany
    {
        return $this->hasMany(ChatConversationUserState::class, 'conversation_id');
    }

    public function latestMessage(): HasOne
    {
        return $this->hasOne(ChatMessage::class, 'conversation_id')->withTrashed()->latestOfMany();
    }

    public function viewerState(?User $user): ?ChatConversationUserState
    {
        if ($user === null) {
            return null;
        }

        if ($this->relationLoaded('userStates')) {
            return $this->userStates->firstWhere('user_id', $user->id);
        }

        return $this->userStates()->where('user_id', $user->id)->first();
    }

    public function involvesCompany(int $companyId): bool
    {
        return (int) $this->company_a_id === $companyId
            || (int) $this->company_b_id === $companyId;
    }

    public function hasCompanyParticipant(?User $user): bool
    {
        $companyId = (int) ($user?->company_id ?? 0);

        return $companyId > 0 && $this->involvesCompany($companyId);
    }

    public function otherCompanyId(int $viewerCompanyId): ?int
    {
        if ((int) $this->company_a_id === $viewerCompanyId) {
            return (int) $this->company_b_id;
        }

        if ((int) $this->company_b_id === $viewerCompanyId) {
            return (int) $this->company_a_id;
        }

        return null;
    }

    public function otherCompany(int $viewerCompanyId): ?Company
    {
        $otherId = $this->otherCompanyId($viewerCompanyId);
        if ($otherId === null) {
            return null;
        }

        if ((int) $this->company_a_id === $otherId) {
            return $this->relationLoaded('companyA') ? $this->companyA : $this->companyA()->first();
        }

        return $this->relationLoaded('companyB') ? $this->companyB : $this->companyB()->first();
    }

    /**
     * @return array{0: int, 1: int}
     */
    public static function orderedCompanyIds(int $companyIdA, int $companyIdB): array
    {
        return $companyIdA < $companyIdB
            ? [$companyIdA, $companyIdB]
            : [$companyIdB, $companyIdA];
    }

    public static function pairKeyFor(int $companyIdA, int $companyIdB, ?int $rentalJobId = null): string
    {
        [$min, $max] = self::orderedCompanyIds($companyIdA, $companyIdB);

        return $rentalJobId
            ? $min.':'.$max.':job:'.$rentalJobId
            : $min.':'.$max;
    }
}
