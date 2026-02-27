<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\ContactSales;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;

class ContactSalesController extends Controller
{
    /**
     * Display a listing of contact sales inquiries.
     */
    public function index()
    {
        $contactSales = ContactSales::orderBy('created_at', 'desc')->get();
        return view('admin.contact-sales.index', compact('contactSales'));
    }

    /**
     * Display the specified contact sales inquiry.
     */
    public function show(ContactSales $contactSales)
    {
        return view('admin.contact-sales.show', compact('contactSales'));
    }

    /**
     * Update the status and admin notes of the contact sales inquiry.
     */
    public function update(Request $request, ContactSales $contactSales)
    {
        $validator = Validator::make($request->all(), [
            'status' => 'required|in:pending,contacted,resolved,closed',
            'admin_notes' => 'nullable|string|max:1000',
        ]);

        if ($validator->fails()) {
            return redirect()->back()
                ->withErrors($validator)
                ->withInput();
        }

        $contactSales->update([
            'status' => $request->status,
            'admin_notes' => $request->admin_notes,
        ]);

        return redirect()->route('admin.contact-sales.show', $contactSales)
            ->with('success', 'Contact sales inquiry updated successfully.');
    }

    /**
     * Remove the specified contact sales inquiry from storage.
     */
    public function destroy(ContactSales $contactSales)
    {
        try {
            $contactSales->delete();
            return redirect()->route('admin.contact-sales.index')
                ->with('success', 'Contact sales inquiry deleted successfully.');
        } catch (\Exception $e) {
            return redirect()->route('admin.contact-sales.index')
                ->with('error', 'Cannot delete contact sales inquiry. ' . $e->getMessage());
        }
    }
}
