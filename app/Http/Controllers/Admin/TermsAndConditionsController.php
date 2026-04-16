<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\TermsAndConditions;
use Illuminate\Http\Request;
use Stevebauman\Purify\Facades\Purify;

class TermsAndConditionsController extends Controller
{
    /**
     * Display the terms and conditions management page
     */
    public function index()
    {
        $terms = TermsAndConditions::getCurrent();

        return view('admin.terms-and-conditions.index', compact('terms'));
    }

    /**
     * Show the form for editing the terms and conditions
     */
    public function edit()
    {
        $terms = TermsAndConditions::getCurrent();

        if (! $terms) {
            // If no terms exist, create a default one
            $terms = new TermsAndConditions;
            $terms->description = '';
        }

        return view('admin.terms-and-conditions.edit', compact('terms'));
    }

    /**
     * Update the terms and conditions
     */
    public function update(Request $request)
    {
        $request->validate([
            'description' => 'required|string',
        ]);

        try {
            $description = Purify::config('wysiwyg')->clean($request->input('description'));

            $terms = TermsAndConditions::getCurrent();

            if ($terms) {
                // Update existing terms
                $terms->update([
                    'description' => $description,
                ]);
            } else {
                // Create new terms if none exist
                TermsAndConditions::create([
                    'description' => $description,
                ]);
            }

            return redirect()
                ->route('admin.terms-and-conditions.index')
                ->with('success', 'Terms and conditions have been updated successfully.');
        } catch (\Exception $e) {
            \Log::error('Failed to update terms and conditions', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);

            return redirect()
                ->back()
                ->withInput()
                ->with('error', 'Failed to update terms and conditions: '.$e->getMessage());
        }
    }
}
