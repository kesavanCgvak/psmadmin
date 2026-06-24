<?php

namespace App\Http\Requests;

use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Http\Exceptions\HttpResponseException;

class FetchInventoryMasterDataRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'product_id' => [
                'required_without:equipment_id',
                'prohibits:equipment_id',
                'nullable',
                'integer',
                'min:1',
                'exists:inventory_master,id',
            ],
            'equipment_id' => [
                'required_without:product_id',
                'prohibits:product_id',
                'nullable',
                'integer',
                'min:1',
                'exists:company_inventory,id',
            ],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'product_id.required_without' => 'Either product_id or equipment_id is required.',
            'equipment_id.required_without' => 'Either product_id or equipment_id is required.',
            'product_id.prohibits' => 'Provide either product_id or equipment_id, not both.',
            'equipment_id.prohibits' => 'Provide either product_id or equipment_id, not both.',
        ];
    }

    protected function failedValidation(Validator $validator): void
    {
        throw new HttpResponseException(response()->json([
            'success' => false,
            'message' => 'Validation error',
            'errors' => $validator->errors(),
        ], 422));
    }
}
