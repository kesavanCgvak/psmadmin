<?php

namespace App\Http\Requests;

use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Validation\Rule;
use Tymon\JWTAuth\Facades\JWTAuth;

class StoreChatConversationRequest extends FormRequest
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
            'company_id' => [
                'required',
                'integer',
                'exists:companies,id',
                Rule::notIn([$this->authenticatedCompanyId()]),
            ],
            'rental_job_id' => ['nullable', 'integer', 'exists:rental_jobs,id'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'company_id.not_in' => 'You cannot start a conversation with your own company.',
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

    private function authenticatedCompanyId(): int
    {
        try {
            return (int) (JWTAuth::parseToken()->authenticate()?->company_id ?? 0);
        } catch (\Throwable) {
            return 0;
        }
    }
}
