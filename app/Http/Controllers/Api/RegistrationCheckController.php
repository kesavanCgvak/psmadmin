<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Company;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;

class RegistrationCheckController extends Controller
{
    /**
     * Public endpoint to check username and/or company name availability.
     * Accepts query/body params: username, company_name. At least one required.
     */
    public function checkAvailability(Request $request): JsonResponse
    {
        $payload = [
            'username' => $request->input('username'),
            'company_name' => $request->input('company_name'),
        ];

        // Basic validation
        $validator = Validator::make($payload, [
            'username' => ['nullable', 'string', 'min:3', 'max:50', 'regex:/^[A-Za-z0-9_\-\.]+$/'],
            'company_name' => ['nullable', 'string', 'min:2', 'max:255'],
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'message' => 'Validation failed',
                'errors' => $validator->errors(),
            ], 422);
        }

        if (empty($payload['username']) && empty($payload['company_name'])) {
            return response()->json([
                'success' => false,
                'message' => 'Provide at least one of: username, company_name',
                'errors' => [
                    'username' => ['username is required when company_name is missing'],
                    'company_name' => ['company_name is required when username is missing'],
                ],
            ], 422);
        }

        // Normalize inputs for case-insensitive comparisons
        $normalizedUsername = $payload['username'] !== null ? mb_strtolower(trim($payload['username'])) : null;
        $normalizedCompany = $payload['company_name'] !== null ? mb_strtolower(trim($payload['company_name'])) : null;

        try {
            $usernameAvailable = null;
            $companyAvailable = null;

            if ($normalizedUsername !== null && $normalizedUsername !== '') {
                $usernameExists = User::query()
                    ->whereRaw('LOWER(username) = ?', [$normalizedUsername])
                    ->exists();
                $usernameAvailable = !$usernameExists;
            }

            if ($normalizedCompany !== null && $normalizedCompany !== '') {
                $companyExists = Company::query()
                    ->whereRaw('LOWER(name) = ?', [$normalizedCompany])
                    ->exists();
                $companyAvailable = !$companyExists;
            }

            return response()->json([
                'success' => true,
                'data' => array_filter([
                    'username' => $usernameAvailable === null ? null : [
                        'input' => $payload['username'],
                        'available' => $usernameAvailable,
                    ],
                    'company_name' => $companyAvailable === null ? null : [
                        'input' => $payload['company_name'],
                        'available' => $companyAvailable,
                    ],
                ], fn($v) => $v !== null),
            ]);
        } catch (\Throwable $e) {
            return response()->json([
                'success' => false,
                'message' => 'Unable to check availability at the moment',
            ], 500);
        }
    }
}


