<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class CountryResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'iso_code' => $this->iso_code,
            'phone_code' => $this->phone_code,
            'region_id' => $this->region_id,
            'region' => new RegionResource($this->whenLoaded('region')),
        ];
    }
}
