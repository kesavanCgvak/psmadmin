<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class CreateOrAttachRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true; // adjust if you use roles
    }

    public function rules(): array
    {
        return [
            'category_id' => ['nullable', 'integer', 'exists:categories,id'],
            'sub_category_id' => ['nullable', 'integer', 'exists:sub_categories,id'],
            'brand_id' => ['nullable', 'integer', 'exists:brands,id'],

            'category.is_new' => ['nullable', 'boolean'],
            'category.name' => ['required_if:category.is_new,true', 'string', 'max:255'],

            'sub_category.is_new' => ['nullable', 'boolean'],
            'sub_category.name' => ['required_if:sub_category.is_new,true', 'string', 'max:255'],

            'brand.is_new' => ['nullable', 'boolean'],
            'brand.name' => ['required_if:brand.is_new,true', 'string', 'max:255'],

            'name' => ['required', 'string', 'max:255'],
            'psm_code' => ['nullable', 'string', 'max:255', 'unique:products,psm_code'],
            'quantity' => ['required', 'integer', 'min:1'],
            'price' => ['required', 'numeric', 'min:0'],
            'rental_software_code' => ['required', 'string', 'max:255'],
        ];
    }
}
