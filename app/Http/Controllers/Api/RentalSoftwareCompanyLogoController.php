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
            $logos = RentalSoftwareCompanyLogo::query()
                ->where('is_active', true)
                ->orderBy('company_name')
                ->get(['id', 'company_name', 'logo_path', 'is_active']);

            $data = $logos->map(function (RentalSoftwareCompanyLogo $logo) {
                return [
                    'id' => $logo->id,
                    'company_name' => $logo->company_name,
                    'logo_path' => InventoryImageManagementService::publicUrl($logo->logo_path),
                    'is_active' => $logo->is_active,
                ];
            });

            return response()->json([
                'success' => true,
                'data' => $data,
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
