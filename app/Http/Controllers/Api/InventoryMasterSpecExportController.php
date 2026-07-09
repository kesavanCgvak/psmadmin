<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\InventoryMaster\InventoryMasterSpecSqlExportService;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Log;
use Tymon\JWTAuth\Facades\JWTAuth;

class InventoryMasterSpecExportController extends Controller
{
    public function __construct(
        private readonly InventoryMasterSpecSqlExportService $exportService,
    ) {
    }

    /**
     * POST /api/inventory-master/specifications/export-sql
     *
     * Generate a MySQL SQL file of inventory_master specification UPDATE statements.
     * Admin / internal use only. Does not modify database records.
     */
    public function export(): JsonResponse
    {
        try {
            $user = JWTAuth::parseToken()->authenticate();

            if (! $user || ! $this->isAdminUser($user)) {
                return response()->json([
                    'success' => false,
                    'message' => 'Admin access required.',
                ], 403);
            }

            $result = $this->exportService->export();

            return response()->json([
                'success' => true,
                'message' => 'Inventory master specification SQL export generated successfully.',
                'data' => [
                    'products_exported' => $result['products_exported'],
                    'filename' => $result['filename'],
                    'path' => $result['path'],
                    'relative_path' => $result['relative_path'],
                ],
            ]);
        } catch (\Throwable $e) {
            Log::error('InventoryMasterSpecExportController: failed to export specifications SQL', [
                'error' => $e->getMessage(),
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Unable to generate inventory master specification SQL export.',
                'error' => config('app.debug') ? $e->getMessage() : null,
            ], 500);
        }
    }

    private function isAdminUser(object $user): bool
    {
        return (bool) ($user->is_admin ?? false)
            || in_array((string) ($user->role ?? ''), ['admin', 'super_admin'], true);
    }
}
