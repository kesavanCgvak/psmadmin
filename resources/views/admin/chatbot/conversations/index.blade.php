@extends('adminlte::page')

@section('title', 'Chatbot Conversations')

@section('content_header')
    <h1>Chatbot Conversations</h1>
@stop

@section('css')
    @include('partials.responsive-css')
@stop

@section('content')
    <div class="card">
        <div class="card-header">
            <h3 class="card-title">Conversation history</h3>
        </div>
        <div class="card-body">
            <form method="GET" action="{{ route('admin.chatbot.conversations.index') }}" class="mb-4">
                <div class="row">
                    <div class="col-md-4">
                        <div class="form-group">
                            <label for="source">Source</label>
                            <select name="source" id="source" class="form-control">
                                <option value="">All sources</option>
                                <option value="api" @selected(request('source') === 'api')>API</option>
                                <option value="admin" @selected(request('source') === 'admin')>Admin</option>
                            </select>
                        </div>
                    </div>
                    <div class="col-md-4">
                        <div class="form-group">
                            <label for="status">Status</label>
                            <select name="status" id="status" class="form-control">
                                <option value="">All statuses</option>
                                <option value="open" @selected(request('status') === 'open')>Open</option>
                                <option value="closed" @selected(request('status') === 'closed')>Closed</option>
                            </select>
                        </div>
                    </div>
                    <div class="col-md-4 d-flex align-items-end">
                        <div class="form-group mb-0 w-100">
                            <button type="submit" class="btn btn-primary btn-block">
                                <i class="fas fa-filter"></i> Filter
                            </button>
                        </div>
                    </div>
                </div>
            </form>

            <div class="table-responsive">
                <table id="chatbotConversationsTable" class="table table-bordered table-striped">
                    <thead>
                        <tr>
                            <th>ID</th>
                            <th>Title</th>
                            <th>User</th>
                            <th>Source</th>
                            <th>Messages</th>
                            <th>Status</th>
                            <th>Last activity</th>
                            <th>Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        @forelse($conversations as $conversation)
                            <tr>
                                <td>{{ $conversation->id }}</td>
                                <td>
                                    <strong>{{ $conversation->title ?: 'Untitled conversation' }}</strong>
                                </td>
                                <td>
                                    <small>{{ $conversation->user?->email ?? 'Guest' }}</small>
                                </td>
                                <td>
                                    <span class="badge badge-secondary">{{ strtoupper($conversation->source) }}</span>
                                </td>
                                <td>
                                    <span class="badge badge-info">{{ $conversation->messages_count }}</span>
                                </td>
                                <td>
                                    <span class="badge badge-{{ $conversation->status === 'open' ? 'success' : 'secondary' }}">
                                        {{ ucfirst($conversation->status) }}
                                    </span>
                                </td>
                                <td>
                                    {{ $conversation->last_message_at?->format(config('app.datetime_format')) ?? '—' }}
                                </td>
                                <td>
                                    <div class="btn-group">
                                        <a href="{{ route('admin.chatbot.conversations.show', $conversation) }}" class="btn btn-info btn-sm" title="View">
                                            <i class="fas fa-eye"></i>
                                        </a>
                                    </div>
                                </td>
                            </tr>
                        @empty
                            <tr>
                                <td colspan="8" class="text-center text-muted py-4">No conversations found.</td>
                            </tr>
                        @endforelse
                    </tbody>
                </table>
            </div>

            @if($conversations->hasPages())
                <div class="mt-3 d-flex justify-content-center pagination-wrapper">
                    {{ $conversations->links('pagination::bootstrap-4') }}
                </div>
            @endif
        </div>
    </div>
@stop

@section('js')
    @include('partials.responsive-js')
    <script>
        $(document).ready(function() {
            @if($conversations->isNotEmpty())
            initResponsiveDataTable('chatbotConversationsTable', {
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
