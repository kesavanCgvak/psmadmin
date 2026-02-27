@extends('adminlte::page')

@section('title', 'Contact Sales Inquiries')

@section('content_header')
    <h1>Contact Sales Inquiries Management</h1>
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

    <div class="card">
        <div class="card-header">
            <h3 class="card-title">All Contact Sales Inquiries</h3>
            <div class="card-tools">
                <span class="badge badge-primary">{{ $contactSales->count() }} Total Inquiries</span>
            </div>
        </div>
        <div class="card-body">
            <div class="table-responsive">
                <table id="contactSalesTable" class="table table-bordered table-striped">
                    <thead>
                        <tr>
                            <th>ID</th>
                            <th>Name</th>
                            <th>Email</th>
                            <th>Phone Number</th>
                            <th>Description</th>
                            <th>Status</th>
                            <th>Submitted At</th>
                            <th>Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach($contactSales as $contact)
                            <tr>
                                <td>{{ $contact->id }}</td>
                                <td><strong>{{ $contact->name }}</strong></td>
                                <td>
                                    <a href="mailto:{{ $contact->email }}">{{ $contact->email }}</a>
                                </td>
                                <td>
                                    @if($contact->phone_number)
                                        <a href="tel:{{ $contact->phone_number }}">{{ $contact->phone_number }}</a>
                                    @else
                                        <span class="text-muted">Not provided</span>
                                    @endif
                                </td>
                                <td>
                                    <small>{{ Str::limit($contact->description, 50) }}</small>
                                </td>
                                <td>
                                    @php
                                        $statusColors = [
                                            'pending' => 'warning',
                                            'contacted' => 'info',
                                            'resolved' => 'success',
                                            'closed' => 'secondary',
                                        ];
                                        $statusColor = $statusColors[$contact->status] ?? 'secondary';
                                    @endphp
                                    <span class="badge badge-{{ $statusColor }}">{{ ucfirst($contact->status) }}</span>
                                </td>
                                <td>{{ $contact->created_at?->format('M d, Y H:i') }}</td>
                                <td>
                                    <div class="btn-group">
                                        <a href="{{ route('admin.contact-sales.show', $contact) }}" class="btn btn-info btn-sm" title="View Details">
                                            <i class="fas fa-eye"></i>
                                        </a>
                                        <form action="{{ route('admin.contact-sales.destroy', $contact) }}" method="POST" class="d-inline" onsubmit="return confirm('Are you sure you want to delete this inquiry?');">
                                            @csrf
                                            @method('DELETE')
                                            <button type="submit" class="btn btn-danger btn-sm" title="Delete">
                                                <i class="fas fa-trash"></i>
                                            </button>
                                        </form>
                                    </div>
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        </div>
    </div>
@stop

@section('js')
    @include('partials.responsive-js')
    <script>
        $(document).ready(function() {
            initResponsiveDataTable('contactSalesTable', {
                "columnDefs": [
                    { "orderable": false, "targets": [-1] },
                    { "responsivePriority": 1, "targets": 1 },
                    { "responsivePriority": 2, "targets": -1 }
                ],
                "order": [[0, "desc"]]
            });
        });
    </script>
@stop
