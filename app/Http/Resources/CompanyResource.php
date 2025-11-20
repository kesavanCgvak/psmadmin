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
            'currency_id' => $this->currency_id,
            'rental_software_id' => $this->rental_software_id,
            'date_format' => $this->date_format,
            'pricing_scheme' => $this->pricing_scheme,
            'address_line_1' => $this->address_line_1,
            'address_line_2' => $this->address_line_2,
            'postal_code' => $this->postal_code,

            // Relations (only if loaded)
            'currency' => new CurrencyResource($this->whenLoaded('currency')),
            'rental_software' => new RentalSoftwareResource($this->whenLoaded('rentalSoftware')),
            'country' => new CountryResource($this->whenLoaded('country')),

            // Optional fields
            'rating' => $this->when(isset($this->rating), (float) $this->rating),
            'is_blocked' => $this->when(isset($this->is_blocked), (bool) $this->is_blocked),
        ];
    }
}
