@extends('adminlte::page')

@section('title', 'SMS Log Details')

@section('content_header')
    <h1>SMS Log Details</h1>
@stop

@section('css')
    @include('partials.responsive-css')
@stop

@php
    $statusColors = [
        'pending' => 'warning',
        'sent' => 'info',
        'delivered' => 'success',
        'failed' => 'danger',
    ];
    $statusColor = $statusColors[$smsLog->status] ?? 'secondary';
@endphp

@section('content')
    <div class="row">
        <div class="col-md-8">
            <div class="card card-primary">
                <div class="card-header">
                    <h3 class="card-title">SMS Details</h3>
                    <div class="card-tools">
                        <span class="badge badge-{{ $statusColor }}">{{ ucfirst($smsLog->status) }}</span>
                    </div>
                </div>
                <div class="card-body">
                    <dl class="row">
                        <dt class="col-sm-4">ID</dt>
                        <dd class="col-sm-8">{{ $smsLog->id }}</dd>

                        <dt class="col-sm-4">Status</dt>
                        <dd class="col-sm-8"><span class="badge badge-{{ $statusColor }}">{{ ucfirst($smsLog->status) }}</span></dd>

                        <dt class="col-sm-4">Provider</dt>
                        <dd class="col-sm-8"><span class="badge badge-secondary">{{ ucfirst($smsLog->provider ?? '-') }}</span></dd>

                        <dt class="col-sm-4">Provider Message ID</dt>
                        <dd class="col-sm-8"><code>{{ $smsLog->provider_message_id ?? '-' }}</code></dd>

                        <dt class="col-sm-4">Recipient Name</dt>
                        <dd class="col-sm-8">{{ $smsLog->recipient_name ?? '-' }}</dd>

                        <dt class="col-sm-4">Phone Number</dt>
                        <dd class="col-sm-8">{{ $smsLog->phone_number ?? '-' }}</dd>

                        <dt class="col-sm-4">Sent By</dt>
                        <dd class="col-sm-8">{{ $smsLog->sent_by ?? '-' }}</dd>

                        <dt class="col-sm-4">Attempts</dt>
                        <dd class="col-sm-8">{{ $smsLog->attempts }}</dd>

                        @if($smsLog->error_message)
                            <dt class="col-sm-4">Error Message</dt>
                            <dd class="col-sm-8">
                                <div class="alert alert-danger mb-0 py-2">{{ $smsLog->error_message }}</div>
                            </dd>
                        @endif

                        <dt class="col-sm-4">Sent At</dt>
                        <dd class="col-sm-8">{{ $smsLog->sent_at?->format('M d, Y H:i:s') ?? '-' }}</dd>

                        <dt class="col-sm-4">Created At</dt>
                        <dd class="col-sm-8">{{ $smsLog->created_at?->format('M d, Y H:i:s') }}</dd>

                        <dt class="col-sm-4">Updated At</dt>
                        <dd class="col-sm-8">{{ $smsLog->updated_at?->format('M d, Y H:i:s') }}</dd>
                    </dl>
                </div>
            </div>

            <div class="card card-secondary">
                <div class="card-header">
                    <h3 class="card-title">Message Content</h3>
                </div>
                <div class="card-body">
                    <p class="mb-0" style="white-space: pre-wrap;">{{ $smsLog->message }}</p>
                </div>
            </div>

            @if($smsLog->provider_response)
                <div class="card">
                    <div class="card-header">
                        <h3 class="card-title">Provider Response</h3>
                    </div>
                    <div class="card-body">
                        <pre class="mb-0" style="max-height: 300px; overflow: auto; background: #f8f9fa; padding: 15px; border-radius: 4px;">{{ json_encode($smsLog->provider_response, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) }}</pre>
                    </div>
                </div>
            @endif
        </div>

        <div class="col-md-4">
            <div class="card card-info">
                <div class="card-header">
                    <h3 class="card-title">Company &amp; Contact</h3>
                </div>
                <div class="card-body">
                    <dl class="row mb-0">
                        <dt class="col-sm-5">Company</dt>
                        <dd class="col-sm-7">{{ $smsLog->company_name ?? '-' }}</dd>

                        <dt class="col-sm-5">Contact Person</dt>
                        <dd class="col-sm-7">{{ $smsLog->contact_person_name ?? '-' }}</dd>

                        <dt class="col-sm-5">Contact Mobile</dt>
                        <dd class="col-sm-7">{{ $smsLog->contact_person_mobile ?? '-' }}</dd>
                    </dl>
                    @if($smsLog->company)
                        <a href="{{ route('admin.companies.show', $smsLog->company) }}" class="btn btn-sm btn-primary mt-2">
                            <i class="fas fa-building"></i> View Company
                        </a>
                    @endif
                </div>
            </div>

            <div class="card card-secondary">
                <div class="card-header">
                    <h3 class="card-title">Related Record</h3>
                </div>
                <div class="card-body">
                    <dl class="row mb-0">
                        <dt class="col-sm-5">Module</dt>
                        <dd class="col-sm-7">{{ $smsLog->related_type ?? '-' }}</dd>

                        <dt class="col-sm-5">Record ID</dt>
                        <dd class="col-sm-7">{{ $smsLog->related_id ?? '-' }}</dd>
                    </dl>
                </div>
            </div>

            <div class="card">
                <div class="card-body">
                    <a href="{{ route('admin.sms-logs.index') }}" class="btn btn-default btn-block">
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
