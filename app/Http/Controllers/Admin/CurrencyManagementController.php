<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Currency;
use App\Services\BulkDeletionService;
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
        // Relation checks before deletion
        if ($currency->companies()->exists()) {
            return redirect()->route('admin.currencies.index')
                ->with('error', 'Cannot delete — this currency is used by one or more companies.');
        }

        try {
            $currency->delete();
            return redirect()->route('admin.currencies.index')
                ->with('success', 'Currency deleted successfully.');
        } catch (\Exception $e) {
            return redirect()->route('admin.currencies.index')
                ->with('error', 'Cannot delete currency. ' . $e->getMessage());
        }
    }

    /**
     * Bulk delete multiple currencies.
     */
    public function bulkDelete(Request $request)
    {
        $request->validate([
            'currency_ids' => 'required|array',
            'currency_ids.*' => 'exists:currencies,id'
        ]);

        $currencies = Currency::whereIn('id', $request->currency_ids)->get();
        $service = new BulkDeletionService();

        $result = $service->deleteWithChecks($currencies->all(), [
            function (Currency $currency) {
                if ($currency->companies()->exists()) {
                    return 'Cannot delete — this currency is used by one or more companies.';
                }
                return null;
            },
        ]);

        $deletedCount = $result['deleted_count'];
        $errors = $result['errors'];
        $blocked = $result['blocked'];

        $messageParts = [];
        if ($deletedCount > 0) {
            $messageParts[] = "Successfully deleted {$deletedCount} currency/currencies.";
        }
        if (!empty($blocked)) {
            $blockedList = array_map(function ($b) {
                return $b['label'] . ' — ' . $b['reason'];
            }, $blocked);
            $messageParts[] = 'Skipped: ' . implode('; ', $blockedList);
        }
        if (!empty($errors)) {
            $messageParts[] = 'Errors: ' . implode('; ', $errors);
        }

        $message = implode(' ', $messageParts) ?: 'No currencies were deleted.';
        $success = $deletedCount > 0;

        if ($request->expectsJson() || $request->ajax()) {
            return response()->json([
                'success' => $success,
                'message' => $message,
                'deleted_count' => $deletedCount,
                'blocked' => $blocked,
                'errors' => $errors
            ]);
        }

        return redirect()->route('admin.currencies.index')
            ->with($success ? 'success' : 'error', $message);
    }
}

