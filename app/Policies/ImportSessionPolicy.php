<?php

namespace App\Policies;

use App\Models\User;
use App\Models\ImportSession;

class ImportSessionPolicy
{
    public function view(User $user, ImportSession $session): bool
    {
        return $this->isProvider($user)
            && $user->company_id === $session->company_id;
    }

    public function update(User $user, ImportSession $session): bool
    {
        return $this->isProvider($user)
            && $user->company_id === $session->company_id
            && $session->status === ImportSession::STATUS_ACTIVE;
    }

    public function confirm(User $user, ImportSession $session): bool
    {
        return $this->isProvider($user)
            && $user->company_id === $session->company_id
            && $session->status === ImportSession::STATUS_ACTIVE;
    }

    protected function isProvider(User $user): bool
    {
        // Handle null case - accessor returns null if company doesn't exist
        if (!$user->company) {
            return false;
        }
        
        return strtolower($user->company->account_type ?? '') === 'provider';
    }
}
