<?php

namespace App\Http\Resources;

use App\Support\DefaultImagePath;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class ProfileResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'user_id' => $this->user_id,
            'profile_picture' => DefaultImagePath::profileImage($this->profile_picture),
            'full_name' => $this->full_name,
            'first_name' => $this->first_name,
            'last_name' => $this->last_name,
            'birthday' => $this->birthday,
            'email' => $this->email,
            'mobile' => $this->mobile,
        ];
    }
}
