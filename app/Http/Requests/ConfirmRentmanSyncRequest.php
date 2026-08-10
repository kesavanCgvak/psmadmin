<?php

namespace App\Http\Requests;

use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Http\Exceptions\HttpResponseException;

class ConfirmRentmanSyncRequest extends FormRequest
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
            'company_inventory_id' => ['required', 'integer', 'min:1', 'exists:company_inventory,id'],
            'create_if_missing' => ['required', 'boolean'],
            'resource_id' => ['required_if:create_if_missing,false', 'nullable', 'string', 'max:100'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'company_inventory_id.required' => 'company_inventory_id is required.',
            'company_inventory_id.exists' => 'Company inventory record not found.',
            'create_if_missing.required' => 'create_if_missing is required.',
            'resource_id.required_if' => 'resource_id is required when create_if_missing is false.',
        ];
    }

    protected function prepareForValidation(): void
    {
        if ($this->has('create_if_missing')) {
            $this->merge([
                'create_if_missing' => filter_var(
                    $this->input('create_if_missing'),
                    FILTER_VALIDATE_BOOLEAN,
                    FILTER_NULL_ON_FAILURE,
                ) ?? $this->input('create_if_missing'),
            ]);
        }
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
