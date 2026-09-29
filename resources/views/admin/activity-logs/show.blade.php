@extends('adminlte::page')

@section('title', 'Activity Log')

@section('content_header')
    <h1>Activity Log</h1>
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

    <div class="mb-3">
        <a href="{{ route('admin.activity-logs.index') }}" class="btn btn-secondary">
            <i class="fas fa-arrow-left"></i> Back to activity logs
        </a>
    </div>

    <div class="card card-primary">
        <div class="card-header">
            <h3 class="card-title">
                @include('admin.activity-logs._action', ['action' => $activityLog->action])
                {{ $activityLog->entityLabel() }} #{{ $activityLog->entity_id }}
            </h3>
        </div>
        <div class="card-body">
            <dl class="row mb-0">
                <dt class="col-sm-3">Date/Time</dt>
                <dd class="col-sm-9">{{ $activityLog->created_at?->format($datetimeFormat) }}</dd>

                <dt class="col-sm-3">Company</dt>
                <dd class="col-sm-9">
                    @if($activityLog->company)
                        <a href="{{ route('admin.companies.show', $activityLog->company) }}">{{ $activityLog->company->name }}</a>
                    @else
                        {{ $activityLog->company_id ? 'Company #'.$activityLog->company_id : '—' }}
                    @endif
                </dd>

                <dt class="col-sm-3">User</dt>
                <dd class="col-sm-9">
                    @if($activityLog->user)
                        <a href="{{ route('admin.users.show', $activityLog->user) }}">{{ $activityLog->user->username }}</a>
                        @if($activityLog->user->email)
                            <span class="text-muted">({{ $activityLog->user->email }})</span>
                        @endif
                    @else
                        {{ $activityLog->user_id ? 'User #'.$activityLog->user_id : 'System' }}
                    @endif
                </dd>

                <dt class="col-sm-3">Action</dt>
                <dd class="col-sm-9">@include('admin.activity-logs._action', ['action' => $activityLog->action])</dd>

                <dt class="col-sm-3">Entity</dt>
                <dd class="col-sm-9">{{ $activityLog->entityLabel() }}</dd>

                <dt class="col-sm-3">Entity ID</dt>
                <dd class="col-sm-9">{{ $activityLog->entity_id }}</dd>

                <dt class="col-sm-3">IP address</dt>
                <dd class="col-sm-9">{{ $activityLog->ip_address ?: '—' }}</dd>

                <dt class="col-sm-3">User agent</dt>
                <dd class="col-sm-9">{{ $activityLog->user_agent ?: '—' }}</dd>
            </dl>
        </div>
        @if($canRestore)
            <div class="card-footer">
                <form method="POST" action="{{ route('admin.activity-logs.restore', $activityLog) }}" onsubmit="return confirm('Restore this record?');">
                    @csrf
                    <button type="submit" class="btn btn-warning">
                        <i class="fas fa-undo"></i> Restore record
                    </button>
                </form>
            </div>
        @endif
    </div>

    <div class="row">
        <div class="col-lg-6">
            <div class="card">
                <div class="card-header">
                    <h3 class="card-title">Previous values</h3>
                </div>
                <div class="card-body">
                    @include('admin.activity-logs._values', ['values' => $oldValues])
                </div>
            </div>
        </div>
        <div class="col-lg-6">
            <div class="card">
                <div class="card-header">
                    <h3 class="card-title">New values</h3>
                </div>
                <div class="card-body">
                    @include('admin.activity-logs._values', ['values' => $newValues])
                </div>
            </div>
        </div>
    </div>
@stop

@section('js')
    @include('partials.responsive-js')
@stop
