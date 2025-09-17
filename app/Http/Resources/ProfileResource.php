<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class ProfileResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'user_id' => $this->user_id,
            'profile_picture' => $this->profile_picture,
            'full_name' => $this->full_name,
            'birthday' => $this->birthday,
            'email' => $this->email,
            'mobile' => $this->mobile,
        ];
    }
}
