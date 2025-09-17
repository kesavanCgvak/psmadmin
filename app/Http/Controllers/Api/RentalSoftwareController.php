<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\RentalSoftware;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

class RentalSoftwareController extends Controller
{
    // Fetch all rental software
    public function index(Request $request)
    {
        try {
            $rentalSoftwares = RentalSoftware::select('id', 'name')
                ->orderBy('name')
                ->get();

            return response()->json([
                'success' => true,
                'data' => $rentalSoftwares
            ], 200); // direct status code
        } catch (\Throwable $e) {
            // log the error for debugging
            Log::error('Error fetching rental softwares: ' . $e->getMessage(), [
                'trace' => $e->getTraceAsString()
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Unable to fetch rental softwares.'
            ], 500);
        }
    }

}
