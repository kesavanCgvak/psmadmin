@extends('adminlte::page')

@section('title', 'Email Log Details')

@section('content_header')
    <h1>Email Log Details</h1>
@stop

@section('css')
    @include('partials.responsive-css')
@stop

@section('content')
    <div class="row">
        <div class="col-md-8">
            <div class="card card-primary">
                <div class="card-header">
                    <h3 class="card-title">Email Details</h3>
                    @php
                        $statusColors = [
                            'pending' => 'warning',
                            'sent' => 'success',
                            'failed' => 'danger',
                        ];
                        $statusColor = $statusColors[$emailLog->status] ?? 'secondary';
                    @endphp
                    <div class="card-tools">
                        <span class="badge badge-{{ $statusColor }}">{{ ucfirst($emailLog->status) }}</span>
                    </div>
                </div>
                <div class="card-body">
                    <dl class="row">
                        <dt class="col-sm-4">ID</dt>
                        <dd class="col-sm-8">{{ $emailLog->id }}</dd>

                        <dt class="col-sm-4">From Email</dt>
                        <dd class="col-sm-8">{{ $emailLog->from_email ?? '-' }}</dd>

                        <dt class="col-sm-4">To Email</dt>
                        <dd class="col-sm-8">
                            <a href="mailto:{{ $emailLog->to_email }}">{{ $emailLog->to_email }}</a>
                        </dd>

                        <dt class="col-sm-4">Subject</dt>
                        <dd class="col-sm-8"><strong>{{ $emailLog->subject ?? '-' }}</strong></dd>

                        <dt class="col-sm-4">Email Type</dt>
                        <dd class="col-sm-8">
                            <span class="badge badge-secondary">{{ ucfirst($emailLog->email_type ?? 'other') }}</span>
                        </dd>

                        <dt class="col-sm-4">Status</dt>
                        <dd class="col-sm-8">
                            <span class="badge badge-{{ $statusColor }}">{{ ucfirst($emailLog->status) }}</span>
                        </dd>

                        @if($emailLog->failure_reason)
                            <dt class="col-sm-4">Failure Reason</dt>
                            <dd class="col-sm-8">
                                <div class="alert alert-danger mb-0 py-2">
                                    {{ $emailLog->failure_reason }}
                                </div>
                            </dd>
                        @endif

                        <dt class="col-sm-4">Mail Class</dt>
                        <dd class="col-sm-8"><code>{{ $emailLog->mail_class ?? '-' }}</code></dd>

                        <dt class="col-sm-4">Reference ID</dt>
                        <dd class="col-sm-8">{{ $emailLog->reference_id ?? '-' }}</dd>

                        <dt class="col-sm-4">Created At</dt>
                        <dd class="col-sm-8">{{ $emailLog->created_at?->format('M d, Y H:i:s') }}</dd>

                        <dt class="col-sm-4">Updated At</dt>
                        <dd class="col-sm-8">{{ $emailLog->updated_at?->format('M d, Y H:i:s') }}</dd>
                    </dl>
                </div>
            </div>

            @if($emailLog->payload_snapshot && count($emailLog->payload_snapshot) > 0)
                <div class="card">
                    <div class="card-header">
                        <h3 class="card-title">Payload Snapshot</h3>
                    </div>
                    <div class="card-body">
                        <pre class="mb-0" style="max-height: 300px; overflow: auto; background: #f8f9fa; padding: 15px; border-radius: 4px;">{{ json_encode($emailLog->payload_snapshot, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) }}</pre>
                    </div>
                </div>
            @endif
        </div>

        <div class="col-md-4">
            @if($emailLog->relatedUser)
                <div class="card card-info">
                    <div class="card-header">
                        <h3 class="card-title">Related User</h3>
                    </div>
                    <div class="card-body">
                        <dl class="row mb-0">
                            <dt class="col-sm-5">User ID</dt>
                            <dd class="col-sm-7">{{ $emailLog->relatedUser->id }}</dd>

                            <dt class="col-sm-5">Username</dt>
                            <dd class="col-sm-7">{{ $emailLog->relatedUser->username ?? '-' }}</dd>

                            <dt class="col-sm-5">Email</dt>
                            <dd class="col-sm-7">{{ $emailLog->relatedUser->email ?? '-' }}</dd>

                            <dt class="col-sm-5">Full Name</dt>
                            <dd class="col-sm-7">{{ $emailLog->relatedUser->profile?->full_name ?? '-' }}</dd>

                            <dt class="col-sm-5">Company</dt>
                            <dd class="col-sm-7">{{ $emailLog->relatedUser->company?->name ?? '-' }}</dd>
                        </dl>
                        <a href="{{ route('admin.users.show', $emailLog->relatedUser) }}" class="btn btn-sm btn-primary mt-2">
                            <i class="fas fa-user"></i> View User
                        </a>
                    </div>
                </div>
            @else
                <div class="card">
                    <div class="card-body">
                        <p class="text-muted mb-0">
                            <i class="fas fa-info-circle"></i> No related user found for this email address.
                        </p>
                    </div>
                </div>
            @endif

            <div class="card">
                <div class="card-body">
                    <a href="{{ route('admin.email-logs.index') }}" class="btn btn-default btn-block">
                        <i class="fas fa-arrow-left"></i> Back to List
                    </a>
                </div>
            </div>
        </div>
    </div>
@stop

@section('js')
    @include('partials.responsive-js')
@stop
