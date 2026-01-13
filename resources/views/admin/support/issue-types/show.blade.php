@extends('adminlte::page')

@section('title', 'Issue Type Details')

@section('content_header')
    <h1>Issue Type Details</h1>
@stop

@section('css')
    @include('partials.responsive-css')
@stop

@section('content')
    <div class="row">
        <div class="col-md-6">
            <div class="card card-primary">
                <div class="card-header">
                    <h3 class="card-title">{{ $issueType->name }}</h3>
                </div>
                <div class="card-body">
                    <dl class="row">
                        <dt class="col-sm-4">ID</dt>
                        <dd class="col-sm-8">{{ $issueType->id }}</dd>

                        <dt class="col-sm-4">Name</dt>
                        <dd class="col-sm-8">{{ $issueType->name }}</dd>

                        <dt class="col-sm-4">Status</dt>
                        <dd class="col-sm-8">
                            @if($issueType->is_active)
                                <span class="badge badge-success">Active</span>
                            @else
                                <span class="badge badge-secondary">Inactive</span>
                            @endif
                        </dd>

                        <dt class="col-sm-4">Support Requests</dt>
                        <dd class="col-sm-8">
                            <span class="badge badge-info">{{ $issueType->supportRequests->count() }}</span>
                        </dd>

                        <dt class="col-sm-4">Created At</dt>
                        <dd class="col-sm-8">{{ $issueType->created_at?->format('M d, Y H:i:s') }}</dd>

                        <dt class="col-sm-4">Updated At</dt>
                        <dd class="col-sm-8">{{ $issueType->updated_at?->format('M d, Y H:i:s') }}</dd>
                    </dl>
                </div>
                <div class="card-footer">
                    <a href="{{ route('admin.issue-types.edit', $issueType) }}" class="btn btn-warning">
                        <i class="fas fa-edit"></i> Edit
                    </a>
                    <a href="{{ route('admin.issue-types.index') }}" class="btn btn-default">
                        <i class="fas fa-arrow-left"></i> Back to List
                    </a>
                </div>
            </div>
        </div>

        <div class="col-md-6">
            <div class="card">
                <div class="card-header">
                    <h3 class="card-title">Support Requests</h3>
                </div>
                <div class="card-body" style="max-height: 400px; overflow-y: auto;">
                    @if($issueType->supportRequests->count() > 0)
                        <div class="table-responsive">
                            <table class="table table-sm table-hover">
                                <thead>
                                    <tr>
                                        <th>ID</th>
                                        <th>Subject</th>
                                        <th>Email</th>
                                        <th>Status</th>
                                        <th>Created</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    @foreach($issueType->supportRequests->take(20) as $request)
                                        <tr>
                                            <td>{{ $request->id }}</td>
                                            <td>{{ Str::limit($request->subject, 30) }}</td>
                                            <td>{{ $request->email }}</td>
                                            <td>
                                                <span class="badge badge-{{ $request->status === 'pending' ? 'warning' : ($request->status === 'resolved' ? 'success' : 'info') }}">
                                                    {{ ucfirst($request->status) }}
                                                </span>
                                            </td>
                                            <td>{{ $request->created_at?->format('M d, Y') }}</td>
                                        </tr>
                                    @endforeach
                                </tbody>
                            </table>
                        </div>
                        @if($issueType->supportRequests->count() > 20)
                            <p class="text-muted mt-2">... and {{ $issueType->supportRequests->count() - 20 }} more support requests</p>
                        @endif
                    @else
                        <p class="text-muted">No support requests for this issue type yet.</p>
                    @endif
                </div>
            </div>
        </div>
    </div>
@stop

