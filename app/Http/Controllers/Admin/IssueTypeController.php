<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\IssueType;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;

class IssueTypeController extends Controller
{
    /**
     * Display a listing of the issue types.
     */
    public function index()
    {
        $issueTypes = IssueType::withCount('supportRequests')->orderBy('name')->get();
        return view('admin.support.issue-types.index', compact('issueTypes'));
    }

    /**
     * Show the form for creating a new issue type.
     */
    public function create()
    {
        return view('admin.support.issue-types.create');
    }

    /**
     * Store a newly created issue type in storage.
     */
    public function store(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'name' => 'required|string|max:255|unique:issue_types,name',
            'is_active' => 'boolean',
        ]);

        if ($validator->fails()) {
            return redirect()->back()
                ->withErrors($validator)
                ->withInput();
        }

        IssueType::create([
            'name' => trim($request->name),
            'is_active' => $request->boolean('is_active'),
        ]);

        return redirect()->route('admin.issue-types.index')
            ->with('success', 'Issue type created successfully.');
    }

    /**
     * Display the specified issue type.
     */
    public function show(IssueType $issueType)
    {
        $issueType->load('supportRequests');
        return view('admin.support.issue-types.show', compact('issueType'));
    }

    /**
     * Show the form for editing the specified issue type.
     */
    public function edit(IssueType $issueType)
    {
        return view('admin.support.issue-types.edit', compact('issueType'));
    }

    /**
     * Update the specified issue type in storage.
     */
    public function update(Request $request, IssueType $issueType)
    {
        $validator = Validator::make($request->all(), [
            'name' => 'required|string|max:255|unique:issue_types,name,' . $issueType->id,
            'is_active' => 'boolean',
        ]);

        if ($validator->fails()) {
            return redirect()->back()
                ->withErrors($validator)
                ->withInput();
        }

        $issueType->update([
            'name' => trim($request->name),
            'is_active' => $request->boolean('is_active'),
        ]);

        return redirect()->route('admin.issue-types.index')
            ->with('success', 'Issue type updated successfully.');
    }

    /**
     * Remove the specified issue type from storage.
     */
    public function destroy(IssueType $issueType)
    {
        if ($issueType->supportRequests()->exists()) {
            return redirect()->route('admin.issue-types.index')
                ->with('error', 'Cannot delete — this issue type has support requests.');
        }

        try {
            $issueType->delete();
            return redirect()->route('admin.issue-types.index')
                ->with('success', 'Issue type deleted successfully.');
        } catch (\Exception $e) {
            return redirect()->route('admin.issue-types.index')
                ->with('error', 'Cannot delete issue type. ' . $e->getMessage());
        }
    }
}
