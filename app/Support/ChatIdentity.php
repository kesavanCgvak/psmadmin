<?php

namespace App\Support;

use App\Models\User;

final class ChatIdentity
{
    public static function displayName(User $user): string
    {
        $user->loadMissing('profile');
        $name = trim((string) ($user->profile?->full_name ?: ''));

        return $name !== '' ? $name : $user->getUsername();
    }

    /**
     * Safe presence payload. Do not add email, phone, JWT, or credentials.
     *
     * @return array{id: int, user_id: int, user_name: string, company_id: int|null}
     */
    public static function presencePayload(User $user): array
    {
        return [
            'id' => (int) $user->id,
            'user_id' => (int) $user->id,
            'user_name' => self::displayName($user),
            'company_id' => $user->company_id ? (int) $user->company_id : null,
        ];
    }
}
