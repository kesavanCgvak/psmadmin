<?php

namespace App\Http\Requests;

use App\Models\PsmProductSubmission;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Validation\Rule;

class StorePsmProductSubmissionRequest extends FormRequest
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
            'name' => ['required', 'string', 'max:255'],
            'description' => ['nullable', 'string', 'max:5000'],
            'psm_code' => [
                'nullable',
                'string',
                'max:255',
                'unique:inventory_master,psm_code',
                Rule::unique('psm_product_submissions', 'psm_code')
                    ->where(fn ($query) => $query->where('status', PsmProductSubmission::STATUS_PENDING)),
            ],
            'brand_id' => ['nullable', 'integer', 'exists:brands,id'],
            'category_id' => ['nullable', 'integer', 'exists:categories,id'],
            'sub_category_id' => ['nullable', 'integer', 'exists:sub_categories,id'],
            'webpage_url' => ['nullable', 'url', 'max:2048'],
            'replacement_price' => ['nullable', 'numeric', 'min:0'],
            'height' => ['nullable', 'numeric', 'min:0'],
            'width' => ['nullable', 'numeric', 'min:0'],
            'length' => ['nullable', 'numeric', 'min:0'],
            'weight' => ['nullable', 'numeric', 'min:0'],
            'linear_unit_id' => ['nullable', 'integer', 'exists:linear_units,id'],
            'weight_unit_id' => ['nullable', 'integer', 'exists:weight_units,id'],
            'country_of_origin' => ['nullable', 'string', 'max:100'],
            'iso_code_2' => ['nullable', 'string', 'max:2'],
            'iso_code_3' => ['nullable', 'string', 'max:3'],
            'hsn_code' => ['nullable', 'string', 'max:20'],
            'images' => ['nullable', 'array', 'max:10'],
            'images.*' => ['image', 'mimes:jpeg,png,jpg,gif,webp', 'max:5120'],
            'primary_image_index' => ['nullable', 'integer', 'min:0'],
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
