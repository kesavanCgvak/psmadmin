<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\DateFormat;
use App\Services\BulkDeletionService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;

class DateFormatManagementController extends Controller
{
    /**
     * Display a listing of the date formats.
     */
    public function index()
    {
        $dateFormats = DateFormat::withCount('companies')->get();
        return view('admin.companies.date-formats.index', compact('dateFormats'));
    }

    /**
     * Show the form for creating a new date format.
     */
    public function create()
    {
        return view('admin.companies.date-formats.create');
    }

    /**
     * Store a newly created date format in storage.
     */
    public function store(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'format' => 'required|string|max:255|unique:date_formats,format',
            'name' => 'required|string|max:255',
            'description' => 'nullable|string',
        ]);

        if ($validator->fails()) {
            return redirect()->back()
                ->withErrors($validator)
                ->withInput();
        }

        DateFormat::create($request->all());

        return redirect()->route('admin.date-formats.index')
            ->with('success', 'Date format created successfully.');
    }

    /**
     * Display the specified date format.
     */
    public function show(DateFormat $dateFormat)
    {
        $dateFormat->load('companies');
        return view('admin.companies.date-formats.show', compact('dateFormat'));
    }

    /**
     * Show the form for editing the specified date format.
     */
    public function edit(DateFormat $dateFormat)
    {
        return view('admin.companies.date-formats.edit', compact('dateFormat'));
    }

    /**
     * Update the specified date format in storage.
     */
    public function update(Request $request, DateFormat $dateFormat)
    {
        $validator = Validator::make($request->all(), [
            'format' => 'required|string|max:255|unique:date_formats,format,' . $dateFormat->id,
            'name' => 'required|string|max:255',
            'description' => 'nullable|string',
        ]);

        if ($validator->fails()) {
            return redirect()->back()
                ->withErrors($validator)
                ->withInput();
        }

        $dateFormat->update($request->all());

        return redirect()->route('admin.date-formats.index')
            ->with('success', 'Date format updated successfully.');
    }

    /**
     * Remove the specified date format from storage.
     */
    public function destroy(DateFormat $dateFormat)
    {
        // Relation checks before deletion
        if ($dateFormat->companies()->exists()) {
            return redirect()->route('admin.date-formats.index')
                ->with('error', 'Cannot delete — this date format is used by one or more companies.');
        }

        try {
            $dateFormat->delete();
            return redirect()->route('admin.date-formats.index')
                ->with('success', 'Date format deleted successfully.');
        } catch (\Exception $e) {
            return redirect()->route('admin.date-formats.index')
                ->with('error', 'Cannot delete date format. ' . $e->getMessage());
        }
    }

    /**
     * Bulk delete multiple date formats.
     */
    public function bulkDelete(Request $request)
    {
        $request->validate([
            'date_format_ids' => 'required|array',
            'date_format_ids.*' => 'exists:date_formats,id'
        ]);

        $dateFormats = DateFormat::whereIn('id', $request->date_format_ids)->get();
        $service = new BulkDeletionService();

        $result = $service->deleteWithChecks($dateFormats->all(), [
            function (DateFormat $dateFormat) {
                if ($dateFormat->companies()->exists()) {
                    return 'Cannot delete — this date format is used by one or more companies.';
                }
                return null;
            },
        ]);

        $deletedCount = $result['deleted_count'];
        $errors = $result['errors'];
        $blocked = $result['blocked'];

        $messageParts = [];
        if ($deletedCount > 0) {
            $messageParts[] = "Successfully deleted {$deletedCount} date format(s).";
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

        $message = implode(' ', $messageParts) ?: 'No date formats were deleted.';
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

        return redirect()->route('admin.date-formats.index')
            ->with($success ? 'success' : 'error', $message);
    }
}
