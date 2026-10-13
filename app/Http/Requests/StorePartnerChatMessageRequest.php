<?php

namespace App\Http\Requests;

use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Validation\Rule;

class StorePartnerChatMessageRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    protected function prepareForValidation(): void
    {
        $trimmed = [];

        foreach (['external_message_id', 'external_conversation_id', 'external_system', 'body'] as $key) {
            if ($this->exists($key) && is_string($this->input($key))) {
                $trimmed[$key] = trim($this->input($key));
            }
        }

        if (isset($trimmed['external_system'])) {
            $trimmed['external_system'] = strtolower($trimmed['external_system']);
        }

        if ($trimmed !== []) {
            $this->merge($trimmed);
        }
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'external_system' => [
                'required',
                'string',
                'max:32',
                Rule::in(config('chat.partner_external_systems', [])),
            ],
            'external_message_id' => ['required', 'string', 'min:1', 'max:191'],
            'external_conversation_id' => ['required', 'string', 'min:1', 'max:191'],
            'psm_conversation_id' => ['nullable', 'integer', 'min:1'],
            'body' => ['required', 'string', 'min:1', 'max:2000'],
            'external_created_at' => ['nullable', 'date'],
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
