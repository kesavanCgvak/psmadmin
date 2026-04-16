@extends('adminlte::page')

@section('title', 'Email Templates')

@section('content_header')
    <h1>Email Templates Management</h1>
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
            <h3 class="card-title">
                <i class="fas fa-envelope"></i> All Email Templates
            </h3>
            <div class="card-tools">
                <small class="text-muted">
                    Manage email templates used throughout the system
                </small>
            </div>
        </div>
        <div class="card-body">
            <table id="templatesTable" class="table table-bordered table-striped">
                <thead>
                    <tr>
                        <th>ID</th>
                        <th>Template Name</th>
                        <th>Subject</th>
                        <th>Description</th>
                        <th>Status</th>
                        <th>Last Updated</th>
                        <th>Actions</th>
                    </tr>
                </thead>
                <tbody>
                    @foreach($templates as $template)
                        <tr>
                            <td>{{ $template->id }}</td>
                            <td>
                                <strong>{{ $template->name }}</strong>
                                @if($template->variables && count($template->variables) > 0)
                                    <br>
                                    <small class="text-muted">
                                        <i class="fas fa-info-circle"></i> 
                                        {{ count($template->variables) }} variable(s)
                                    </small>
                                @endif
                            </td>
                            <td>
                                <span title="{{ $template->subject }}">
                                    {{ Str::limit($template->subject, 50) }}
                                </span>
                            </td>
                            <td>
                                @if($template->description)
                                    <span title="{{ $template->description }}">
                                        {{ Str::limit($template->description, 60) }}
                                    </span>
                                @else
                                    <span class="text-muted">-</span>
                                @endif
                            </td>
                            <td>
                                @if($template->is_active)
                                    <span class="badge badge-success">
                                        <i class="fas fa-check-circle"></i> Active
                                    </span>
                                @else
                                    <span class="badge badge-secondary">
                                        <i class="fas fa-times-circle"></i> Inactive
                                    </span>
                                @endif
                            </td>
                            <td>
                                {{ $template->updated_at->format('M d, Y') }}
                                <br>
                                <small class="text-muted">
                                    {{ $template->updated_at->format('g:i A') }}
                                </small>
                            </td>
                            <td>
                                <div class="btn-group">
                                    <a href="{{ route('admin.email-templates.edit', $template) }}" 
                                       class="btn btn-warning btn-sm" 
                                       title="Edit">
                                        <i class="fas fa-edit"></i>
                                    </a>
                                    <a href="{{ route('admin.email-templates.preview', $template) }}" 
                                       class="btn btn-info btn-sm" 
                                       title="Preview"
                                       target="_blank">
                                        <i class="fas fa-eye"></i>
                                    </a>
                                    <form action="{{ route('admin.email-templates.toggle-status', $template) }}" 
                                          method="POST" 
                                          class="d-inline toggle-status-form"
                                          data-template-name="{{ $template->name }}"
                                          data-action="{{ $template->is_active ? 'deactivate' : 'activate' }}">
                                        @csrf
                                        @method('POST')
                                        <button type="button" 
                                                class="btn btn-sm {{ $template->is_active ? 'btn-secondary' : 'btn-success' }} toggle-status-btn" 
                                                title="{{ $template->is_active ? 'Deactivate' : 'Activate' }}">
                                            <i class="fas fa-{{ $template->is_active ? 'toggle-on' : 'toggle-off' }}"></i>
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

    <div class="card">
        <div class="card-header">
            <h3 class="card-title">
                <i class="fas fa-info-circle"></i> Information
            </h3>
        </div>
        <div class="card-body">
            <div class="alert alert-info">
                <h5><i class="icon fas fa-info"></i> About Email Templates</h5>
                <ul class="mb-0">
                    <li><strong>Template Name:</strong> Unique identifier used in code (e.g., 'registrationSuccess', 'forgotPassword')</li>
                    <li><strong>Subject:</strong> Email subject line - can include variables like @{{name}} or @{{$name}}</li>
                    <li><strong>Body:</strong> HTML email content - supports full HTML and CSS styling</li>
                    <li><strong>Variables:</strong> Dynamic content that gets replaced when sending emails</li>
                    <li><strong>Status:</strong> Active templates are used when sending emails. <strong>Disabled templates will prevent emails from being sent</strong> - no emails will be delivered for that template type until reactivated.</li>
                    <li><strong>Preview:</strong> Test templates with sample data before sending</li>
                </ul>
            </div>
        </div>
    </div>

    <div class="modal fade" id="toggleStatusModal" tabindex="-1" role="dialog" aria-labelledby="toggleStatusModalLabel" aria-hidden="true">
        <div class="modal-dialog modal-dialog-centered" role="document">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title" id="toggleStatusModalLabel">
                        <i class="fas fa-info-circle text-primary"></i> <span id="toggleStatusModalTitle">Confirm</span>
                    </h5>
                    <button type="button" class="close" data-dismiss="modal" aria-label="Close">
                        <span aria-hidden="true">&times;</span>
                    </button>
                </div>
                <div class="modal-body">
                    <p id="toggleStatusModalMessage" class="mb-0"></p>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-dismiss="modal">Cancel</button>
                    <button type="button" class="btn btn-primary" id="toggleStatusConfirmBtn">
                        <i class="fas fa-check"></i> Confirm
                    </button>
                </div>
            </div>
        </div>
    </div>
@stop

@section('js')
    @include('partials.responsive-js')
    <script>
        $(document).ready(function() {
            var table = initResponsiveDataTable('templatesTable', {
                order: [[0, 'asc']],
                pageLength: 25,
                columnDefs: [
                    { orderable: false, targets: [6] } // Disable sorting on Actions column
                ]
            });

            var $formToSubmit = null;
            $(document).on('click', '.toggle-status-btn', function(e) {
                e.preventDefault();
                $formToSubmit = $(this).closest('form.toggle-status-form');
                var templateName = $formToSubmit.data('template-name');
                var action = $formToSubmit.data('action');
                var title = action === 'deactivate' ? 'Deactivate template?' : 'Activate template?';
                var message = action === 'deactivate'
                    ? 'Are you sure you want to deactivate the template "<strong>' + templateName + '</strong>"? <br><br><strong>Warning:</strong> When disabled, emails using this template will NOT be sent at all. Users will not receive these emails until you reactivate the template.'
                    : 'Are you sure you want to activate the template "<strong>' + templateName + '</strong>"? This will enable emails to be sent using this template.';
                $('#toggleStatusModalTitle').text(title);
                $('#toggleStatusModalMessage').html(message);
                $('#toggleStatusModal').modal('show');
            });
            $('#toggleStatusConfirmBtn').on('click', function() {
                if ($formToSubmit && $formToSubmit.length) {
                    $formToSubmit.submit();
                }
                $('#toggleStatusModal').modal('hide');
            });
        });
    </script>
@stop
