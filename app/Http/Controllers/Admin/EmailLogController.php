<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\EmailLog;
use Illuminate\Http\Request;

class EmailLogController extends Controller
{
    /**
     * Display a listing of email logs with filters.
     */
    public function index(Request $request)
    {
        $query = EmailLog::with('relatedUser.profile')
            ->orderBy('created_at', 'desc');

        if ($request->filled('status')) {
            $query->where('status', $request->status);
        }

        if ($request->filled('email_type')) {
            $query->where('email_type', $request->email_type);
        }

        if ($request->filled('date_from')) {
            $query->whereDate('created_at', '>=', $request->date_from);
        }

        if ($request->filled('date_to')) {
            $query->whereDate('created_at', '<=', $request->date_to);
        }

        if ($request->filled('search')) {
            $search = $request->search;
            $query->where(function ($q) use ($search) {
                $q->where('from_email', 'like', "%{$search}%")
                    ->orWhere('to_email', 'like', "%{$search}%")
                    ->orWhere('subject', 'like', "%{$search}%");
            });
        }

        $emailLogs = $query->paginate(25)->withQueryString();

        $emailTypes = EmailLog::select('email_type')
            ->distinct()
            ->whereNotNull('email_type')
            ->orderBy('email_type')
            ->pluck('email_type');

        return view('admin.email-logs.index', compact('emailLogs', 'emailTypes'));
    }

    /**
     * Display the specified email log.
     */
    public function show(EmailLog $emailLog)
    {
        $emailLog->load('relatedUser.profile', 'relatedUser.company');

        return view('admin.email-logs.show', compact('emailLog'));
    }
}
