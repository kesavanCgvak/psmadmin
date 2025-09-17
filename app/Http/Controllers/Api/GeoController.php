<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Region;
use App\Models\Country;
use App\Models\City;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Response;

class GeoController extends Controller
{
    public function getRegions()
    {
        try {
            $regions = Region::select('id', 'name')->get();
            return response()->json(['success' => true, 'data' => $regions]);
        } catch (\Exception $e) {
            Log::error('Failed to fetch regions: ' . $e->getMessage());
            return response()->json(['success' => false, 'message' => 'Unable to fetch regions'], 500);
        }
    }

    public function getCountries()
    {
        try {
            $countries = Country::select('id', 'name')->orderBy('name')->get();

            if ($countries->isEmpty()) {
                return response()->json(['success' => false, 'message' => 'No countries found'], 404);
            }

            return response()->json(['success' => true, 'data' => $countries], 200);
        } catch (\Exception $e) {
            Log::error("Error fetching countries: " . $e->getMessage());
            return response()->json(['success' => false, 'message' => 'Unable to fetch countries'], 500);
        }
    }


    public function getCountriesByRegion($regionId)
    {
        try {
            $region = Region::find($regionId);
            if (!$region) {
                return response()->json(['success' => false, 'message' => 'Region not found'], 404);
            }

            $Countries = $region->Countries()->select('id', 'name')->get();
            return response()->json(['success' => true, 'data' => $Countries]);
        } catch (\Exception $e) {
            Log::error("Error fetching Countries for region ID $regionId: " . $e->getMessage());
            return response()->json(['success' => false, 'message' => 'Unable to fetch Countries'], 500);
        }
    }

    public function getCitiesByCountry($CountryId)
    {
        try {
            $Country = Country::find($CountryId);
            if (!$Country) {
                return response()->json(['success' => false, 'message' => 'Country not found'], 404);
            }

            $cities = $Country->cities()->select('id', 'name', 'latitude', 'longitude')->get();
            return response()->json(['success' => true, 'data' => $cities]);
        } catch (\Exception $e) {
            Log::error("Error fetching cities for Country ID $CountryId: " . $e->getMessage());
            return response()->json(['success' => false, 'message' => 'Unable to fetch cities'], 500);
        }
    }
}
