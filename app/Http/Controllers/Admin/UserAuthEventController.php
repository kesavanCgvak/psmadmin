<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\UserAuthEvent;
use Illuminate\Http\Request;

class UserAuthEventController extends Controller
{
    /**
     * Display login / logout / failed login history for admin review.
     */
    public function index(Request $request)
    {
        $query = UserAuthEvent::with(['user.profile'])
            ->orderBy('created_at', 'desc');

        if ($request->filled('event_type')) {
            $query->where('event_type', $request->event_type);
        }

        if ($request->filled('channel')) {
            $query->where('channel', $request->channel);
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
                $q->where('identifier', 'like', "%{$search}%")
                    ->orWhere('ip_address', 'like', "%{$search}%")
                    ->orWhereHas('user', function ($uq) use ($search) {
                        $uq->where('username', 'like', "%{$search}%")
                            ->orWhere('email', 'like', "%{$search}%")
                            ->orWhereHas('profile', function ($pq) use ($search) {
                                $pq->where('full_name', 'like', "%{$search}%")
                                    ->orWhere('email', 'like', "%{$search}%");
                            });
                    });
            });
        }

        $perPage = config('app.admin_list_per_page', 25);
        $events = $query->paginate($perPage)->withQueryString();

        $datetimeFormat = config('app.datetime_format', 'M d, Y H:i');

        return view('admin.user-auth-events.index', compact('events', 'datetimeFormat'));
    }
}
