<?php

namespace App\Http\Requests;

use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Http\Exceptions\HttpResponseException;

class UpdateChatNotificationSettingsRequest extends FormRequest
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
            'browser_notifications_enabled' => ['sometimes', 'boolean'],
            'email_notifications_enabled' => ['sometimes', 'boolean'],
            'sms_notifications_enabled' => ['sometimes', 'boolean'],
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator): void {
            if (
                ! $this->exists('browser_notifications_enabled')
                && ! $this->exists('email_notifications_enabled')
                && ! $this->exists('sms_notifications_enabled')
            ) {
                $validator->errors()->add(
                    'browser_notifications_enabled',
                    'At least one notification setting is required.'
                );
            }
        });
    }

    protected function prepareForValidation(): void
    {
        foreach ([
            'browser_notifications_enabled',
            'email_notifications_enabled',
            'sms_notifications_enabled',
        ] as $field) {
            if ($this->exists($field)) {
                $this->merge([
                    $field => filter_var(
                        $this->input($field),
                        FILTER_VALIDATE_BOOLEAN,
                        FILTER_NULL_ON_FAILURE
                    ),
                ]);
            }
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
