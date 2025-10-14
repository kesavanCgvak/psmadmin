<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Currency;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;

class CurrencyManagementController extends Controller
{
    /**
     * Display a listing of the currencies.
     */
    public function index()
    {
        $currencies = Currency::withCount('companies')->get();
        return view('admin.companies.currencies.index', compact('currencies'));
    }

    /**
     * Show the form for creating a new currency.
     */
    public function create()
    {
        return view('admin.companies.currencies.create');
    }

    /**
     * Store a newly created currency in storage.
     */
    public function store(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'code' => 'required|string|max:255|unique:currencies,code',
            'name' => 'required|string|max:255',
            'symbol' => 'required|string|max:255',
        ]);

        if ($validator->fails()) {
            return redirect()->back()
                ->withErrors($validator)
                ->withInput();
        }

        Currency::create($request->all());

        return redirect()->route('admin.currencies.index')
            ->with('success', 'Currency created successfully.');
    }

    /**
     * Display the specified currency.
     */
    public function show(Currency $currency)
    {
        $currency->load('companies');
        return view('admin.companies.currencies.show', compact('currency'));
    }

    /**
     * Show the form for editing the specified currency.
     */
    public function edit(Currency $currency)
    {
        return view('admin.companies.currencies.edit', compact('currency'));
    }

    /**
     * Update the specified currency in storage.
     */
    public function update(Request $request, Currency $currency)
    {
        $validator = Validator::make($request->all(), [
            'code' => 'required|string|max:255|unique:currencies,code,' . $currency->id,
            'name' => 'required|string|max:255',
            'symbol' => 'required|string|max:255',
        ]);

        if ($validator->fails()) {
            return redirect()->back()
                ->withErrors($validator)
                ->withInput();
        }

        $currency->update($request->all());

        return redirect()->route('admin.currencies.index')
            ->with('success', 'Currency updated successfully.');
    }

    /**
     * Remove the specified currency from storage.
     */
    public function destroy(Currency $currency)
    {
        try {
            $currency->delete();
            return redirect()->route('admin.currencies.index')
                ->with('success', 'Currency deleted successfully.');
        } catch (\Exception $e) {
            return redirect()->route('admin.currencies.index')
                ->with('error', 'Cannot delete currency. It may be used by companies.');
        }
    }
}

