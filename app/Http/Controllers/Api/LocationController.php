<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Region;
use App\Models\Country;
use App\Models\StateProvince;
use App\Models\City;
use Illuminate\Http\Request;

class LocationController extends Controller
{
    public function hierarchy(Request $request) {
        $region = Region::find($request->region_id);
        $country = Country::find($request->country_id);
        $state = StateProvince::find($request->state_id);
        $city = City::find($request->city_id);

        return response()->json([
            'success' => true,
            'data' => [
                'region' => $region ? ['id' => $region->id, 'name' => $region->name] : null,
                'country' => $country ? ['id' => $country->id, 'name' => $country->name, 'iso_code' => $country->iso_code] : null,
                'state' => $state ? ['id' => $state->id, 'name' => $state->name, 'code' => $state->code, 'type' => $state->type] : null,
                'city' => $city ? ['id' => $city->id, 'name' => $city->name, 'latitude' => $city->latitude, 'longitude' => $city->longitude] : null,
            ]
        ]);
    }
}
