@extends('adminlte::page')

@section('title', 'Email Log Details')

@section('content_header')
    <h1>Email Log Details</h1>
@stop

@section('css')
    @include('partials.responsive-css')
@stop

@section('content')
    @if(session('success'))
        <div class="alert alert-success alert-dismissible fade show">
            <button type="button" class="close" data-dismiss="alert">&times;</button>
            <i class="icon fas fa-check"></i> {{ session('success') }}
        </div>
    @endif

    @if(session('error'))
        <div class="alert alert-danger alert-dismissible fade show">
            <button type="button" class="close" data-dismiss="alert">&times;</button>
            <i class="icon fas fa-ban"></i> {{ session('error') }}
        </div>
    @endif

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

                        <dt class="col-sm-4">Retry Attempts</dt>
                        <dd class="col-sm-8">{{ $emailLog->retryCount() }}</dd>

                        @if($emailLog->retryMeta()['last_attempt_at'])
                            <dt class="col-sm-4">Last Retry At</dt>
                            <dd class="col-sm-8">{{ \Illuminate\Support\Carbon::parse($emailLog->retryMeta()['last_attempt_at'])->format('M d, Y H:i:s') }}</dd>
                        @endif
                    </dl>
                </div>
            </div>

            @php
                $snapshot = $emailLog->payload_snapshot ?? [];
                $attachmentNames = collect($snapshot['attachments'] ?? [])->pluck('filename')->filter()->values();
            @endphp
            <div class="card">
                <div class="card-header">
                    <h3 class="card-title">Retry Content</h3>
                </div>
                <div class="card-body">
                    <dl class="row mb-0">
                        <dt class="col-sm-4">Available to resend</dt>
                        <dd class="col-sm-8">
                            @if($emailLog->hasStoredContent() && empty($snapshot['attachments_incomplete']))
                                Yes
                            @else
                                No
                            @endif
                        </dd>

                        @if(!empty($snapshot['cc']))
                            <dt class="col-sm-4">Cc</dt>
                            <dd class="col-sm-8">{{ implode(', ', $snapshot['cc']) }}</dd>
                        @endif

                        @if(!empty($snapshot['bcc']))
                            <dt class="col-sm-4">Bcc</dt>
                            <dd class="col-sm-8">{{ implode(', ', $snapshot['bcc']) }}</dd>
                        @endif

                        @if(!empty($snapshot['reply_to']))
                            <dt class="col-sm-4">Reply-To</dt>
                            <dd class="col-sm-8">{{ implode(', ', $snapshot['reply_to']) }}</dd>
                        @endif

                        @if($attachmentNames->isNotEmpty())
                            <dt class="col-sm-4">Attachments</dt>
                            <dd class="col-sm-8">{{ $attachmentNames->implode(', ') }}</dd>
                        @endif

                        @if(!empty($snapshot['content_omit_reason']))
                            <dt class="col-sm-4">Content note</dt>
                            <dd class="col-sm-8">{{ $snapshot['content_omit_reason'] }}</dd>
                        @endif

                        @if(!empty($snapshot['attachments_incomplete']))
                            <dt class="col-sm-4">Attachment note</dt>
                            <dd class="col-sm-8">An attachment could not be stored, so this email cannot be resent in full.</dd>
                        @endif
                    </dl>
                    <p class="text-muted mb-0 mt-3">
                        Message content is stored so a pending or failed email can be resent. The body is not shown here.
                    </p>
                </div>
            </div>
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
                    @include('admin.email-logs.partials.retry-action', ['emailLog' => $emailLog, 'showLabel' => true])
                    <a href="{{ route('admin.email-logs.index') }}" class="btn btn-default btn-block mt-2">
                        <i class="fas fa-arrow-left"></i> Back to List
                    </a>
                </div>
            </div>
        </div>
    </div>
@stop

@section('js')
    @include('partials.responsive-js')
    @include('admin.email-logs.partials.retry-script')
@stop
