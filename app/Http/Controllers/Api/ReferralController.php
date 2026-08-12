<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Company;
use App\Services\ReferralService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Validator;
use Tymon\JWTAuth\Facades\JWTAuth;

class ReferralController extends Controller
{
    private const SEARCH_MIN_LENGTH = 2;
    private const SEARCH_RESULT_LIMIT = 10;

    public function __construct(private ReferralService $referralService)
    {
    }

    /**
     * Generate or return the authenticated company's reusable referral link.
     */
    public function store(): JsonResponse
    {
        try {
            $user = JWTAuth::parseToken()->authenticate();
            $company = $user?->company;

            if (!$company) {
                return response()->json([
                    'success' => false,
                    'message' => 'Company not found',
                ], 404);
            }

            $link = $this->referralService->getOrCreateActiveLink($company);

            return response()->json([
                'success' => true,
                'data' => [
                    'referral_code' => $link->referral_code,
                    'referral_url' => $this->referralService->buildReferralUrl($link->referral_code),
                ],
            ], 200);
        } catch (\Exception $e) {
            Log::error('Failed to generate referral link', [
                'error' => $e->getMessage(),
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Unable to generate referral link',
            ], 500);
        }
    }

    /**
     * Validate a referral code and return basic referring company info.
     */
    public function show(string $referral_code): JsonResponse
    {
        $result = $this->referralService->validateReferralCode($referral_code);

        if (!$result['valid']) {
            return response()->json([
                'success' => true,
                'data' => $result,
            ], 200);
        }

        return response()->json([
            'success' => true,
            'data' => $result,
        ], 200);
    }

    /**
     * Public typeahead search for the registration "Referred By" field.
     * Returns a limited list of company id + name only.
     */
    public function searchCompanies(Request $request): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'q' => ['required', 'string', 'min:'.self::SEARCH_MIN_LENGTH, 'max:255'],
        ], [
            'q.required' => 'A search keyword is required.',
            'q.min' => 'Search keyword must be at least '.self::SEARCH_MIN_LENGTH.' characters.',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'message' => 'Validation failed',
                'errors' => $validator->errors(),
            ], 422);
        }

        $keyword = trim((string) $request->input('q'));
        $escapedKeyword = addcslashes($keyword, '%_\\');
        $normalizedKeyword = mb_strtolower($escapedKeyword);

        // Keyword search first, then eligible filters, then relevance order, then limit.
        // Prefix matches rank above mid-string matches so names like "Sound Engineers"
        // are not pushed out by alphabetically earlier matches (e.g. "A-Live Sound LTD").
        $companies = Company::query()
            ->select(['id', 'name'])
            ->whereNull('blocked_by_admin_at')
            ->whereRaw('LOWER(name) LIKE ?', ['%'.$normalizedKeyword.'%'])
            ->orderByRaw(
                'CASE WHEN LOWER(name) LIKE ? THEN 0 WHEN LOWER(name) LIKE ? THEN 1 ELSE 2 END',
                [$normalizedKeyword.'%', '% '.$normalizedKeyword.'%']
            )
            ->orderBy('name')
            ->limit(self::SEARCH_RESULT_LIMIT)
            ->get()
            ->map(fn (Company $company) => [
                'id' => (int) $company->id,
                'name' => (string) $company->name,
            ])
            ->values();

        return response()->json([
            'success' => true,
            'data' => $companies,
        ], 200);
    }
}
