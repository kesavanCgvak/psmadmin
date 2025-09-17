<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class CompanyResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'logo' => $this->logo,
            'description' => $this->description,
            'image1' => $this->image1,
            'image2' => $this->image2,
            'image3' => $this->image3,
            'rental_software' => $this->rental_software,
        ];
    }
}
