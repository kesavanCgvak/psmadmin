<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class UserResource extends JsonResource
{
    /**
     * Transform the resource into an array.
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'account_type' => $this->account_type,
            'username' => $this->username,
            'name' => $this->name,
            'email' => $this->email,
            'email_verified' => (bool) $this->email_verified,
            'company_id' => $this->company_id,
            'is_company_default_contact' => $this->is_company_default_contact,
            'is_admin' => $this->is_admin,
            'role' => $this->role,
            'stripe_customer_id' => $this->stripe_customer_id,
            'is_blocked' => (bool) $this->is_blocked,

            // only selected profile fields
            'profile' => new ProfileResource($this->whenLoaded('profile')),

            // only selected company fields
            'company' => new CompanyResource($this->whenLoaded('company')),
        ];
    }
}
