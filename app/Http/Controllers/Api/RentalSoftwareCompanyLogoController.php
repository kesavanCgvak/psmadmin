<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\RentalSoftwareCompanyLogo;
use App\Support\InventoryImageManagementService;
use Illuminate\Support\Facades\Log;

class RentalSoftwareCompanyLogoController extends Controller
{
    /**
     * Return active rental software company logos for frontend display.
     */
    public function index()
    {
        try {
            Log::info('RentalSoftwareCompanyLogo API: request started', [
                'db_connection' => config('database.default'),
                'db_database' => config('database.connections.' . config('database.default') . '.database'),
                'env_db_database' => env('DB_DATABASE'),
            ]);

            $query = RentalSoftwareCompanyLogo::query()
                ->where('is_active', true)
                ->orderBy('sort_order', 'asc')
                ->orderBy('id', 'asc')
                ->select(['id', 'company_name', 'logo_path', 'is_active']);

            Log::info('RentalSoftwareCompanyLogo API: SQL query', [
                'sql' => $query->toSql(),
                'bindings' => $query->getBindings(),
            ]);

            $logos = $query->get();

            Log::info('RentalSoftwareCompanyLogo API: query results', [
                'count' => $logos->count(),
                'results' => $logos->toArray(),
            ]);

            $data = $logos->map(function (RentalSoftwareCompanyLogo $logo) {
                return [
                    'id' => $logo->id,
                    'company_name' => $logo->company_name,
                    'logo_path' => InventoryImageManagementService::publicUrl($logo->logo_path),
                    'is_active' => $logo->is_active,
                ];
            });

            Log::info('RentalSoftwareCompanyLogo API: response data', [
                'count' => $data->count(),
                'data' => $data->values()->all(),
            ]);

            return response()->json([
                'success' => true,
                'data' => $data->values(),
            ], 200);
        } catch (\Throwable $e) {
            Log::error('Error fetching rental software company logos: ' . $e->getMessage(), [
                'trace' => $e->getTraceAsString(),
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Unable to fetch rental software company logos.',
            ], 500);
        }
    }
}
