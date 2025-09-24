<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Resources\StateResource;
use App\Models\Country;
use App\Models\StateProvince;
use Illuminate\Http\Request;

class StateController extends Controller
{
    public function index(Country $country)
    {
        $states = $country->states()->orderBy('name')->get();
        return response()->json(['success' => true, 'data' => StateResource::collection($states)]);
    }

    public function show(StateProvince $state)
    {
        return response()->json(['success' => true, 'data' => new StateResource($state)]);
    }
}
