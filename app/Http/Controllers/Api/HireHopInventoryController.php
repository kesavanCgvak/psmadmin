<?php

namespace App\Http\Controllers\Api;

use App\Exceptions\HireHopApiException;
use App\Http\Controllers\Controller;
use App\Models\User;
use App\Services\HireHopInventoryImportService;
use App\Services\HireHopService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Validator;
use Tymon\JWTAuth\Facades\JWTAuth;

class HireHopInventoryController extends Controller
{
    /**
     * Browse or search HireHop rental products.
     * GET /api/hirehop/search?keyword=&page=&rows=
     */
    public function search(Request $request): JsonResponse
    {
        $context = $this->companyContext();
        if ($context instanceof JsonResponse) {
            return $context;
        }

        $keyword = trim((string) $request->query('keyword', ''));
        if ($keyword !== '' && strlen($keyword) < 2) {
            return response()->json([
                'success' => false,
                'message' => 'Keyword must be at least 2 characters.',
            ], 422);
        }
        if (strlen($keyword) > 200) {
            return response()->json([
                'success' => false,
                'message' => 'Keyword must be 200 characters or fewer.',
            ], 422);
        }

        $page = $request->query('page', 1);
        $rows = $request->query('rows', config('hirehop.list_rows_default', 20));
        if (!is_numeric($page) || (int) $page < 1) {
            return response()->json([
                'success' => false,
                'message' => 'page must be 1 or greater.',
            ], 422);
        }
        if (!is_numeric($rows) || (int) $rows < 1) {
            return response()->json([
                'success' => false,
                'message' => 'rows must be 1 or greater.',
            ], 422);
        }

        try {
            $result = HireHopService::forCompany($context['company_id'])->listRentalProducts(
                (int) $page,
                (int) $rows,
                $keyword === '' ? null : $keyword
            );
        } catch (HireHopApiException $e) {
            return $this->hireHopError($e);
        } catch (\Throwable $e) {
            $this->logUnexpected('HireHop search error', $context['company_id'], $e);

            return response()->json([
                'success' => false,
                'message' => 'An unexpected error occurred while searching HireHop products.',
            ], 500);
        }

        return response()->json([
            'success' => true,
            'data' => array_map(static function (array $product): array {
                return [
                    'hirehop_stock_id' => $product['hirehop_stock_id'],
                    'name' => $product['name'],
                    'part_number' => $product['part_number'],
                    'barcode' => $product['barcode'],
                    'status' => $product['status'],
                    'type' => $product['type'],
                ];
            }, $result['products']),
            'pagination' => [
                'page' => $result['page'],
                'rows' => $result['rows'],
                'total_pages' => $result['total_pages'],
                'total_records' => $result['total_records'],
            ],
        ]);
    }

    /**
     * GET /api/hirehop/import/check?hirehop_stock_id=
     */
    public function checkImport(Request $request): JsonResponse
    {
        $context = $this->companyContext();
        if ($context instanceof JsonResponse) {
            return $context;
        }

        $stockId = trim((string) ($request->query('hirehop_stock_id') ?? ''));
        if ($stockId === '') {
            return response()->json([
                'success' => false,
                'message' => 'hirehop_stock_id is required.',
            ], 422);
        }

        try {
            $result = HireHopInventoryImportService::checkImportStatus($context['company_id'], $stockId);
        } catch (HireHopApiException $e) {
            return $this->hireHopError($e);
        } catch (\Throwable $e) {
            $this->logUnexpected('HireHop import check error', $context['company_id'], $e);

            return response()->json([
                'success' => false,
                'message' => 'An unexpected error occurred while checking import status.',
            ], 500);
        }

        return response()->json($this->checkBody($result));
    }

    /**
     * POST /api/hirehop/import
     * Payload: { hirehop_stock_id, quantity, rental_price?, rental_rate?, confirm?, product_id? }
     */
    public function import(Request $request): JsonResponse
    {
        $context = $this->companyContext();
        if ($context instanceof JsonResponse) {
            return $context;
        }

        $validator = Validator::make($request->all(), [
            'hirehop_stock_id' => ['required', 'regex:/^\d+$/'],
            'quantity' => 'required|numeric|min:1',
            'rental_rate' => 'nullable|numeric|min:0',
            'rental_price' => 'nullable|numeric|min:0',
            'confirm' => 'nullable|boolean',
            'product_id' => 'nullable|integer|min:1',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'message' => 'Validation failed.',
                'errors' => $validator->errors(),
            ], 422);
        }

        $rentalOverride = null;
        if ($request->exists('rental_price') && $request->input('rental_price') !== null && $request->input('rental_price') !== '') {
            $rentalOverride = (float) $request->input('rental_price');
        } elseif ($request->exists('rental_rate') && $request->input('rental_rate') !== null && $request->input('rental_rate') !== '') {
            $rentalOverride = (float) $request->input('rental_rate');
        }

        try {
            $result = HireHopInventoryImportService::import(
                $context['company_id'],
                (int) $context['user']->id,
                (string) $request->input('hirehop_stock_id'),
                (int) $request->input('quantity'),
                $rentalOverride,
                $request->boolean('confirm', true),
                $request->filled('product_id') ? (int) $request->input('product_id') : null
            );
        } catch (HireHopApiException $e) {
            return $this->hireHopError($e);
        } catch (\Throwable $e) {
            $this->logUnexpected('HireHop import error', $context['company_id'], $e);

            return response()->json([
                'success' => false,
                'message' => 'An unexpected error occurred during import.',
            ], 500);
        }

        return response()->json($result['body'], $result['status']);
    }

    /**
     * POST /api/hirehop/link-inventory
     * Payload: { inventory_id, hirehop_stock_id, quantity?, rental_price?, rental_rate? }
     */
    public function linkInventory(Request $request): JsonResponse
    {
        $context = $this->companyContext();
        if ($context instanceof JsonResponse) {
            return $context;
        }

        $validator = Validator::make($request->all(), [
            'inventory_id' => 'required|integer|min:1',
            'hirehop_stock_id' => ['required', 'regex:/^\d+$/'],
            'quantity' => 'nullable|integer|min:1',
            'rental_rate' => 'nullable|numeric|min:0',
            'rental_price' => 'nullable|numeric|min:0',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'message' => 'Validation failed.',
                'errors' => $validator->errors(),
            ], 422);
        }

        $rentalOverride = null;
        if ($request->filled('rental_price')) {
            $rentalOverride = (float) $request->input('rental_price');
        } elseif ($request->filled('rental_rate')) {
            $rentalOverride = (float) $request->input('rental_rate');
        }

        try {
            $result = HireHopInventoryImportService::link(
                $context['company_id'],
                (int) $request->input('inventory_id'),
                (string) $request->input('hirehop_stock_id'),
                $request->filled('quantity') ? (int) $request->input('quantity') : null,
                $rentalOverride
            );
        } catch (HireHopApiException $e) {
            return $this->hireHopError($e);
        } catch (\Throwable $e) {
            $this->logUnexpected('HireHop link-inventory error', $context['company_id'], $e);

            return response()->json([
                'success' => false,
                'message' => 'An unexpected error occurred while linking HireHop.',
            ], 500);
        }

        return response()->json($result['body'], $result['status']);
    }

    /**
     * @param  array<string, mixed>  $result
     * @return array<string, mixed>
     */
    private function checkBody(array $result): array
    {
        $status = (string) ($result['status'] ?? '');

        if ($status === 'already_in_inventory') {
            return [
                'status' => 'already_in_inventory',
                'message' => $result['message'] ?? 'Already imported',
                'brand_name' => $result['brand_name'] ?? null,
                'model' => $result['model'] ?? null,
                'inventory_id' => $result['inventory_id'] ?? null,
                'hirehop' => $result['hirehop'] ?? null,
                'psm' => $result['psm'] ?? null,
            ];
        }

        if ($status === 'inventory_exists') {
            return [
                'status' => 'inventory_exists',
                'inventory_id' => $result['inventory_id'],
                'brand_name' => $result['brand_name'] ?? null,
                'model' => $result['model'] ?? null,
                'hirehop' => $result['hirehop'] ?? null,
                'psm' => $result['psm'] ?? null,
            ];
        }

        if ($status === 'product_exists') {
            return [
                'status' => 'product_exists',
                'product_id' => $result['product_id'],
                'day_rate' => $result['day_rate'] ?? null,
                'brand_name' => $result['brand_name'] ?? null,
                'model' => $result['model'] ?? null,
                'hirehop' => $result['hirehop'] ?? null,
                'psm' => $result['psm'] ?? null,
            ];
        }

        return [
            'status' => 'new_product',
            'day_rate' => $result['day_rate'] ?? null,
            'hirehop' => $result['hirehop'] ?? null,
        ];
    }

    /**
     * @return array{user: User, company_id: int}|JsonResponse
     */
    private function companyContext(): array|JsonResponse
    {
        $user = $this->authenticatedUser();
        if (!$user) {
            return response()->json(['success' => false, 'message' => 'Unauthorized'], 401);
        }

        $companyId = (int) ($user->company_id ?? 0);
        if ($companyId < 1) {
            return response()->json([
                'success' => false,
                'message' => 'Company not found for this user.',
            ], 403);
        }

        return [
            'user' => $user,
            'company_id' => $companyId,
        ];
    }

    private function authenticatedUser(): ?User
    {
        try {
            $user = JWTAuth::parseToken()->authenticate();

            return $user instanceof User ? $user : null;
        } catch (\Exception $e) {
            return null;
        }
    }

    private function hireHopError(HireHopApiException $exception): JsonResponse
    {
        return response()->json([
            'success' => false,
            'message' => $exception->getMessage(),
            'error_code' => $exception->errorCode(),
        ], $exception->httpStatus());
    }

    private function logUnexpected(string $message, int $companyId, \Throwable $exception): void
    {
        Log::error($message, [
            'company_id' => $companyId,
            'exception' => $exception::class,
        ]);
    }
}
