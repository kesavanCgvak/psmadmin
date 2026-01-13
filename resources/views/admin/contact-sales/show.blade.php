@extends('adminlte::page')

@section('title', 'Contact Sales Inquiry Details')

@section('content_header')
    <h1>Contact Sales Inquiry Details</h1>
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
            <!-- Contact Information -->
            <div class="card card-primary">
                <div class="card-header">
                    <h3 class="card-title">Contact Information</h3>
                    <div class="card-tools">
                        @php
                            $statusColors = [
                                'pending' => 'warning',
                                'contacted' => 'info',
                                'resolved' => 'success',
                                'closed' => 'secondary',
                            ];
                            $statusColor = $statusColors[$contactSales->status] ?? 'secondary';
                        @endphp
                        <span class="badge badge-{{ $statusColor }}">{{ ucfirst($contactSales->status) }}</span>
                    </div>
                </div>
                <div class="card-body">
                    <dl class="row">
                        <dt class="col-sm-4">ID</dt>
                        <dd class="col-sm-8">{{ $contactSales->id }}</dd>

                        <dt class="col-sm-4">Name</dt>
                        <dd class="col-sm-8"><strong>{{ $contactSales->name }}</strong></dd>

                        <dt class="col-sm-4">Email Address</dt>
                        <dd class="col-sm-8">
                            <a href="mailto:{{ $contactSales->email }}">{{ $contactSales->email }}</a>
                        </dd>

                        <dt class="col-sm-4">Phone Number</dt>
                        <dd class="col-sm-8">
                            @if($contactSales->phone_number)
                                <a href="tel:{{ $contactSales->phone_number }}">{{ $contactSales->phone_number }}</a>
                            @else
                                <span class="text-muted">Not provided</span>
                            @endif
                        </dd>

                        <dt class="col-sm-4">Status</dt>
                        <dd class="col-sm-8">
                            <span class="badge badge-{{ $statusColor }}">{{ ucfirst($contactSales->status) }}</span>
                        </dd>

                        <dt class="col-sm-4">Submitted At</dt>
                        <dd class="col-sm-8">{{ $contactSales->created_at?->format('M d, Y H:i:s') }}</dd>

                        <dt class="col-sm-4">Last Updated</dt>
                        <dd class="col-sm-8">{{ $contactSales->updated_at?->format('M d, Y H:i:s') }}</dd>
                    </dl>
                </div>
            </div>

            <!-- Description -->
            <div class="card">
                <div class="card-header">
                    <h3 class="card-title">Message / Questions</h3>
                </div>
                <div class="card-body">
                    <div style="white-space: pre-wrap; word-wrap: break-word; padding: 15px; background-color: #f9f9f9; border-left: 4px solid #1a73e8;">
                        {{ $contactSales->description }}
                    </div>
                </div>
            </div>
        </div>

        <div class="col-md-4">
            <!-- Update Status and Notes -->
            <div class="card card-warning">
                <div class="card-header">
                    <h3 class="card-title">Update Status & Notes</h3>
                </div>
                <form action="{{ route('admin.contact-sales.update', $contactSales) }}" method="POST">
                    @csrf
                    @method('PUT')
                    <div class="card-body">
                        <div class="form-group">
                            <label for="status">Status</label>
                            <select name="status" id="status" class="form-control" required>
                                <option value="pending" {{ $contactSales->status === 'pending' ? 'selected' : '' }}>Pending</option>
                                <option value="contacted" {{ $contactSales->status === 'contacted' ? 'selected' : '' }}>Contacted</option>
                                <option value="resolved" {{ $contactSales->status === 'resolved' ? 'selected' : '' }}>Resolved</option>
                                <option value="closed" {{ $contactSales->status === 'closed' ? 'selected' : '' }}>Closed</option>
                            </select>
                        </div>

                        <div class="form-group">
                            <label for="admin_notes">Admin Notes</label>
                            <textarea name="admin_notes" id="admin_notes" class="form-control" rows="5" placeholder="Add internal notes about this inquiry...">{{ old('admin_notes', $contactSales->admin_notes) }}</textarea>
                            <small class="form-text text-muted">These notes are for internal use only and will not be visible to the contact.</small>
                        </div>
                    </div>
                    <div class="card-footer">
                        <button type="submit" class="btn btn-primary">
                            <i class="fas fa-save"></i> Update
                        </button>
                    </div>
                </form>
            </div>

            <!-- Admin Notes Display -->
            @if($contactSales->admin_notes)
                <div class="card">
                    <div class="card-header">
                        <h3 class="card-title">Admin Notes</h3>
                    </div>
                    <div class="card-body">
                        <div style="white-space: pre-wrap; word-wrap: break-word;">
                            {{ $contactSales->admin_notes }}
                        </div>
                    </div>
                </div>
            @endif

            <!-- Actions -->
            <div class="card">
                <div class="card-body">
                    <a href="{{ route('admin.contact-sales.index') }}" class="btn btn-default btn-block">
                        <i class="fas fa-arrow-left"></i> Back to List
                    </a>
                    <form action="{{ route('admin.contact-sales.destroy', $contactSales) }}" method="POST" class="mt-2" onsubmit="return confirm('Are you sure you want to delete this inquiry?');">
                        @csrf
                        @method('DELETE')
                        <button type="submit" class="btn btn-danger btn-block">
                            <i class="fas fa-trash"></i> Delete Inquiry
                        </button>
                    </form>
                </div>
            </div>
        </div>
    </div>
@stop

@section('js')
    @include('partials.responsive-js')
@stop
