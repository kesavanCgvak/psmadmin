<?php
namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Resources\CityResource;
use App\Models\Country;
use App\Models\StateProvince;
use Illuminate\Http\Request;

class CityController extends Controller
{
    public function indexByState(StateProvince $state)
    {
        $cities = $state->cities()->orderBy('name')->get();
        return response()->json(['success' => true, 'data' => CityResource::collection($cities)]);
    }

    public function indexByCountry(Country $country)
    { // legacy
        $cities = $country->cities()->whereNull('state_id')->orderBy('name')->get();
        return response()->json(['success' => true, 'data' => CityResource::collection($cities)]);
    }
}
