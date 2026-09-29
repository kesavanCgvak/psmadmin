<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\ActivityLog;
use App\Models\Company;
use App\Services\ActivityLogService;
use Illuminate\Database\QueryException;
use Illuminate\Http\Request;

class ActivityLogController extends Controller
{
    public function __construct(private readonly ActivityLogService $activityLogService)
    {
    }

    public function index(Request $request)
    {
        $query = ActivityLog::query()
            ->with(['company:id,name', 'user:id,username,email'])
            ->orderByDesc('created_at');

        if ($request->filled('company_id')) {
            $query->where('company_id', (int) $request->input('company_id'));
        }

        if ($request->filled('action')) {
            $query->where('action', $request->input('action'));
        }

        if ($request->filled('entity_type')) {
            $query->where('entity_type', $request->input('entity_type'));
        }

        if ($request->filled('entity_id')) {
            $query->where('entity_id', (int) $request->input('entity_id'));
        }

        if ($request->filled('date_from')) {
            $query->whereDate('created_at', '>=', $request->input('date_from'));
        }

        if ($request->filled('date_to')) {
            $query->whereDate('created_at', '<=', $request->input('date_to'));
        }

        if ($request->filled('user')) {
            $search = trim((string) $request->input('user'));
            $query->where(function ($q) use ($search) {
                if (ctype_digit($search)) {
                    $q->where('user_id', (int) $search);
                }

                $q->orWhereHas('user', function ($userQuery) use ($search) {
                    $userQuery->where('username', 'like', "%{$search}%")
                        ->orWhere('email', 'like', "%{$search}%");
                });
            });
        }

        $perPage = config('app.admin_list_per_page', 25);
        $logs = $query->paginate($perPage)->withQueryString();
        $companies = Company::query()->orderBy('name')->get(['id', 'name']);
        $datetimeFormat = config('app.datetime_format', 'M d, Y H:i');
        $actions = [
            ActivityLog::ACTION_CREATED,
            ActivityLog::ACTION_UPDATED,
            ActivityLog::ACTION_DELETED,
            ActivityLog::ACTION_RESTORED,
        ];
        $entityTypes = [
            ActivityLog::ENTITY_COMPANY_INVENTORY => 'Company inventory',
            ActivityLog::ENTITY_COMPANY_INTEGRATION => 'Company integration',
        ];

        return view('admin.activity-logs.index', compact(
            'logs',
            'companies',
            'datetimeFormat',
            'actions',
            'entityTypes'
        ));
    }

    public function show(ActivityLog $activityLog)
    {
        $activityLog->load(['company:id,name', 'user:id,username,email']);
        $datetimeFormat = config('app.datetime_format', 'M d, Y H:i');
        $oldValues = $this->activityLogService->forDisplay($activityLog->old_values);
        $newValues = $this->activityLogService->forDisplay($activityLog->new_values);
        $canRestore = $this->activityLogService->restorableRecord($activityLog) !== null;

        return view('admin.activity-logs.show', compact(
            'activityLog',
            'datetimeFormat',
            'oldValues',
            'newValues',
            'canRestore'
        ));
    }

    public function restore(ActivityLog $activityLog)
    {
        $record = $this->activityLogService->restorableRecord($activityLog);
        if (!$record) {
            return redirect()
                ->route('admin.activity-logs.show', $activityLog)
                ->with('error', 'This record is not available to restore.');
        }

        try {
            $record->restore();
        } catch (QueryException $e) {
            return redirect()
                ->route('admin.activity-logs.show', $activityLog)
                ->with('error', 'Could not restore this record because an active record already uses the same integration or equipment link.');
        }

        return redirect()
            ->route('admin.activity-logs.show', $activityLog)
            ->with('success', 'Record restored.');
    }
}
