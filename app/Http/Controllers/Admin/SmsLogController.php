<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\SmsLog;
use Illuminate\Http\Request;

class SmsLogController extends Controller
{
    /**
     * Display a paginated, filterable listing of SMS logs.
     */
    public function index(Request $request)
    {
        $query = SmsLog::query()->orderBy('created_at', 'desc');

        if ($request->filled('status')) {
            $query->where('status', $request->status);
        }

        if ($request->filled('provider')) {
            $query->where('provider', $request->provider);
        }

        if ($request->filled('related_type')) {
            $query->where('related_type', $request->related_type);
        }

        if ($request->filled('company_name')) {
            $query->where('company_name', 'like', '%' . $request->company_name . '%');
        }

        if ($request->filled('contact_person_name')) {
            $query->where('contact_person_name', 'like', '%' . $request->contact_person_name . '%');
        }

        if ($request->filled('phone_number')) {
            $query->where('phone_number', 'like', '%' . $request->phone_number . '%');
        }

        if ($request->filled('date_from')) {
            $query->whereDate('created_at', '>=', $request->date_from);
        }

        if ($request->filled('date_to')) {
            $query->whereDate('created_at', '<=', $request->date_to);
        }

        // Broad search across the most useful identifying columns.
        if ($request->filled('search')) {
            $search = $request->search;
            $query->where(function ($q) use ($search) {
                $q->where('company_name', 'like', "%{$search}%")
                    ->orWhere('contact_person_name', 'like', "%{$search}%")
                    ->orWhere('recipient_name', 'like', "%{$search}%")
                    ->orWhere('phone_number', 'like', "%{$search}%")
                    ->orWhere('provider_message_id', 'like', "%{$search}%")
                    ->orWhere('related_id', 'like', "%{$search}%")
                    ->orWhere('message', 'like', "%{$search}%");
            });
        }

        $perPage = config('app.admin_list_per_page', 25);
        $smsLogs = $query->paginate($perPage)->withQueryString();

        // Filter dropdown sources (kept lightweight via distinct + index usage).
        $providers = SmsLog::query()
            ->select('provider')
            ->distinct()
            ->whereNotNull('provider')
            ->orderBy('provider')
            ->pluck('provider');

        $relatedTypes = SmsLog::query()
            ->select('related_type')
            ->distinct()
            ->whereNotNull('related_type')
            ->orderBy('related_type')
            ->pluck('related_type');

        $statuses = SmsLog::statuses();

        $datetimeFormat = config('app.datetime_format', 'M d, Y H:i');

        return view('admin.sms-logs.index', compact(
            'smsLogs',
            'providers',
            'relatedTypes',
            'statuses',
            'datetimeFormat'
        ));
    }

    /**
     * Display the full detail of a single SMS log.
     */
    public function show(SmsLog $smsLog)
    {
        $smsLog->load('company');

        return view('admin.sms-logs.show', compact('smsLog'));
    }
}
