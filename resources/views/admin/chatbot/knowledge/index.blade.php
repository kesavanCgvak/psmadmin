@extends('adminlte::page')

@section('title', 'Chatbot Knowledge')

@section('content_header')
    <h1>Chatbot Knowledge</h1>
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

    <div class="card">
        <div class="card-header">
            <h3 class="card-title">Knowledge base entries</h3>
            <div class="card-tools">
                <a href="{{ route('admin.chatbot.knowledge.create') }}" class="btn btn-primary btn-sm">
                    <i class="fas fa-plus"></i> New entry
                </a>
            </div>
        </div>
        <div class="card-body">
            <form method="GET" action="{{ route('admin.chatbot.knowledge.index') }}" class="mb-4">
                <div class="row">
                    <div class="col-md-4">
                        <div class="form-group">
                            <label for="q">Search</label>
                            <input type="text" name="q" id="q" value="{{ request('q') }}" class="form-control" placeholder="Title, question, content…">
                        </div>
                    </div>
                    <div class="col-md-3">
                        <div class="form-group">
                            <label for="category">Category</label>
                            <select name="category" id="category" class="form-control">
                                <option value="">All categories</option>
                                @foreach($categories as $category)
                                    <option value="{{ $category }}" @selected(request('category') === $category)>{{ $category }}</option>
                                @endforeach
                            </select>
                        </div>
                    </div>
                    <div class="col-md-3">
                        <div class="form-group">
                            <label for="status">Status</label>
                            <select name="status" id="status" class="form-control">
                                <option value="">All statuses</option>
                                <option value="active" @selected(request('status') === 'active')>Active</option>
                                <option value="inactive" @selected(request('status') === 'inactive')>Inactive</option>
                            </select>
                        </div>
                    </div>
                    <div class="col-md-2 d-flex align-items-end">
                        <div class="form-group mb-0 w-100">
                            <button type="submit" class="btn btn-primary btn-block">
                                <i class="fas fa-filter"></i> Filter
                            </button>
                        </div>
                    </div>
                </div>
            </form>

            <div class="table-responsive">
                <table id="chatbotKnowledgeTable" class="table table-bordered table-striped">
                    <thead>
                        <tr>
                            <th>ID</th>
                            <th>Title</th>
                            <th>Category</th>
                            <th>Status</th>
                            <th>Sort</th>
                            <th>Updated</th>
                            <th>Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        @forelse($entries as $entry)
                            <tr>
                                <td>{{ $entry->id }}</td>
                                <td>
                                    <strong>{{ $entry->title }}</strong>
                                    @if($entry->question)
                                        <div class="text-muted small">Q: {{ Str::limit($entry->question, 80) }}</div>
                                    @endif
                                </td>
                                <td>
                                    @if($entry->category)
                                        <span class="badge badge-info">{{ $entry->category }}</span>
                                    @else
                                        <span class="text-muted">—</span>
                                    @endif
                                </td>
                                <td>
                                    @if($entry->is_active)
                                        <span class="badge badge-success">Active</span>
                                    @else
                                        <span class="badge badge-secondary">Inactive</span>
                                    @endif
                                </td>
                                <td>{{ $entry->sort_order }}</td>
                                <td>{{ $entry->updated_at->format(config('app.datetime_format')) }}</td>
                                <td>
                                    <div class="btn-group">
                                        <a href="{{ route('admin.chatbot.knowledge.show', $entry) }}" class="btn btn-info btn-sm" title="View">
                                            <i class="fas fa-eye"></i>
                                        </a>
                                        <a href="{{ route('admin.chatbot.knowledge.edit', $entry) }}" class="btn btn-warning btn-sm" title="Edit">
                                            <i class="fas fa-edit"></i>
                                        </a>
                                        <form action="{{ route('admin.chatbot.knowledge.destroy', $entry) }}" method="POST" class="d-inline" onsubmit="return confirm('Delete this knowledge entry?');">
                                            @csrf
                                            @method('DELETE')
                                            <button type="submit" class="btn btn-danger btn-sm" title="Delete">
                                                <i class="fas fa-trash"></i>
                                            </button>
                                        </form>
                                    </div>
                                </td>
                            </tr>
                        @empty
                            <tr>
                                <td colspan="7" class="text-center text-muted py-4">No knowledge entries found.</td>
                            </tr>
                        @endforelse
                    </tbody>
                </table>
            </div>

            @if($entries->hasPages())
                <div class="mt-3 d-flex justify-content-center pagination-wrapper">
                    {{ $entries->links('pagination::bootstrap-4') }}
                </div>
            @endif
        </div>
    </div>
@stop

@section('js')
    @include('partials.responsive-js')
    <script>
        $(document).ready(function() {
            @if($entries->isNotEmpty())
            initResponsiveDataTable('chatbotKnowledgeTable', {
                "paging": false,
                "ordering": true,
                "info": false,
                "searching": false,
                "columnDefs": [
                    { "orderable": false, "targets": [-1] },
                    { "responsivePriority": 1, "targets": 1 },
                    { "responsivePriority": 2, "targets": -1 }
                ]
            });
            @endif
        });
    </script>
@stop
