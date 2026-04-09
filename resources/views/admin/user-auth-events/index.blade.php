@extends('adminlte::page')

@section('title', 'User Login Activity')

@section('content_header')
    <h1>User Login Activity</h1>
@stop

@section('css')
    @include('partials.responsive-css')
@stop

@section('content')
    <div class="card">
        <div class="card-header">
            <div class="d-flex flex-wrap justify-content-between align-items-center w-100">
                <h3 class="card-title mb-2 mb-md-0">
                    <i class="fas fa-sign-in-alt"></i> Logins, logouts, and failed attempts
                </h3>
                <small class="text-muted">
                    Web (admin panel) and API (mobile/app) events
                </small>
            </div>
        </div>
        <div class="card-body">
            <form method="GET" action="{{ route('admin.user-auth-events.index') }}" class="mb-3 p-3 border rounded bg-light">
                <div class="row">
                    <div class="col-md-6 col-lg-2">
                        <div class="form-group">
                            <label for="event_type">Event</label>
                            <select name="event_type" id="event_type" class="form-control">
                                <option value="">All</option>
                                <option value="login" {{ request('event_type') === 'login' ? 'selected' : '' }}>Login</option>
                                <option value="logout" {{ request('event_type') === 'logout' ? 'selected' : '' }}>Logout</option>
                                <option value="failed_login" {{ request('event_type') === 'failed_login' ? 'selected' : '' }}>Failed login</option>
                            </select>
                        </div>
                    </div>
                    <div class="col-md-6 col-lg-2">
                        <div class="form-group">
                            <label for="channel">Channel</label>
                            <select name="channel" id="channel" class="form-control">
                                <option value="">All</option>
                                <option value="web" {{ request('channel') === 'web' ? 'selected' : '' }}>Web</option>
                                <option value="api" {{ request('channel') === 'api' ? 'selected' : '' }}>API</option>
                            </select>
                        </div>
                    </div>
                    <div class="col-md-6 col-lg-2">
                        <div class="form-group">
                            <label for="date_from">From date</label>
                            <input type="date" name="date_from" id="date_from" class="form-control" value="{{ request('date_from') }}">
                        </div>
                    </div>
                    <div class="col-md-6 col-lg-2">
                        <div class="form-group">
                            <label for="date_to">To date</label>
                            <input type="date" name="date_to" id="date_to" class="form-control" value="{{ request('date_to') }}">
                        </div>
                    </div>
                    <div class="col-md-6 col-lg-2">
                        <div class="form-group">
                            <label for="search">Search</label>
                            <input type="text" name="search" id="search" class="form-control" placeholder="User, email, IP…" value="{{ request('search') }}">
                        </div>
                    </div>
                    <div class="col-md-6 col-lg-2">
                        <div class="form-group mb-0 w-100">
                            <label class="d-block invisible mb-2">Filter</label>
                            <div class="btn-group btn-group-sm d-flex" role="group">
                                <button type="submit" class="btn btn-primary w-50">
                                    <i class="fas fa-filter"></i> Filter
                                </button>
                                <a href="{{ route('admin.user-auth-events.index') }}" class="btn btn-outline-secondary w-50">
                                    <i class="fas fa-undo"></i> Reset
                                </a>
                            </div>
                        </div>
                    </div>
                </div>
            </form>

            <div class="alert alert-light border mb-3 py-2">
                <small class="text-muted">
                    <strong>Identifier:</strong> email (web) or username (API) submitted on
                    <span class="badge badge-warning">Failed login</span>. For successful login/logout, use the <strong>User</strong> column.
                </small>
            </div>

            <div class="table-responsive">
                <table id="userAuthEventsTable" class="table table-bordered table-striped table-hover">
                    <thead>
                        <tr>
                            <th>Time</th>
                            <th>Event</th>
                            <th>Channel</th>
                            <th>User</th>
                            <th>Identifier</th>
                            <th>IP</th>
                            <th>User agent</th>
                        </tr>
                    </thead>
                    <tbody>
                        @forelse($events as $event)
                            <tr>
                                <td>{{ $event->created_at?->format($datetimeFormat) }}</td>
                                <td>
                                    @if($event->event_type === 'login')
                                        <span class="badge badge-success">Login</span>
                                    @elseif($event->event_type === 'logout')
                                        <span class="badge badge-secondary">Logout</span>
                                    @else
                                        <span class="badge badge-warning">Failed login</span>
                                    @endif
                                </td>
                                <td>{{ strtoupper($event->channel) }}</td>
                                <td>
                                    @if($event->user)
                                        <a href="{{ route('admin.users.show', $event->user) }}">{{ $event->user->username }}</a>
                                        @if($event->user->profile?->full_name)
                                            <br><small class="text-muted">{{ $event->user->profile->full_name }}</small>
                                        @endif
                                    @else
                                        <span class="text-muted">—</span>
                                    @endif
                                </td>
                                <td>{{ $event->identifier ?? '—' }}</td>
                                <td><code>{{ $event->ip_address ?? '—' }}</code></td>
                                <td>
                                    @php
                                        $ua = (string) ($event->user_agent ?? '');
                                        $browser = 'Unknown browser';
                                        $os = 'Unknown OS';
                                        $device = 'Desktop';

                                        if (preg_match('/Edg\//i', $ua)) {
                                            $browser = 'Microsoft Edge';
                                        } elseif (preg_match('/OPR\/|Opera/i', $ua)) {
                                            $browser = 'Opera';
                                        } elseif (preg_match('/Chrome\//i', $ua) && !preg_match('/Edg\//i', $ua)) {
                                            $browser = 'Chrome';
                                        } elseif (preg_match('/Safari\//i', $ua) && !preg_match('/Chrome\//i', $ua)) {
                                            $browser = 'Safari';
                                        } elseif (preg_match('/Firefox\//i', $ua)) {
                                            $browser = 'Firefox';
                                        }

                                        if (preg_match('/Windows/i', $ua)) {
                                            $os = 'Windows';
                                        } elseif (preg_match('/Android/i', $ua)) {
                                            $os = 'Android';
                                        } elseif (preg_match('/iPhone|iPad|iPod/i', $ua)) {
                                            $os = 'iOS';
                                        } elseif (preg_match('/Mac OS X|Macintosh/i', $ua)) {
                                            $os = 'macOS';
                                        } elseif (preg_match('/Linux/i', $ua)) {
                                            $os = 'Linux';
                                        }

                                        if (preg_match('/iPad/i', $ua)) {
                                            $device = 'Tablet';
                                        } elseif (preg_match('/Mobile|iPhone|Android/i', $ua)) {
                                            $device = 'Mobile';
                                        }

                                        $uaSummary = $browser . ' on ' . $os . ' (' . $device . ')';
                                    @endphp
                                    <small class="text-muted d-block" title="{{ $ua }}">
                                        {{ $uaSummary }}
                                    </small>
                                    @if($ua !== '')
                                        <small class="text-muted d-block">{{ \Illuminate\Support\Str::limit($ua, 55) }}</small>
                                    @endif
                                </td>
                            </tr>
                        @empty
                            <tr>
                                <td colspan="7" class="text-center text-muted">No events found.</td>
                            </tr>
                        @endforelse
                    </tbody>
                </table>
            </div>

            <div class="d-flex justify-content-center">
                {{ $events->links() }}
            </div>
        </div>
    </div>
@stop

@section('js')
    @include('partials.responsive-js')
    <script>
        $(document).ready(function() {
            initResponsiveDataTable('userAuthEventsTable', {
                paging: false,
                searching: false,
                info: false,
                lengthChange: false,
                ordering: true,
                columnDefs: [
                    { orderable: false, targets: [6] },
                    { responsivePriority: 1, targets: 0 },
                    { responsivePriority: 2, targets: 3 },
                    { responsivePriority: 3, targets: 1 }
                ]
            });
        });
    </script>
@stop
