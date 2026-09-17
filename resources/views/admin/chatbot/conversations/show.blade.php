@extends('adminlte::page')

@section('title', 'Conversation Details')

@section('content_header')
    <h1>Conversation Details</h1>
@stop

@section('css')
    @include('partials.responsive-css')
    <link rel="stylesheet" href="{{ asset('common/css/chatbot.css') }}">
@stop

@section('content')
    <div class="row">
        <div class="col-md-8">
            <div class="card card-primary">
                <div class="card-header">
                    <h3 class="card-title">{{ $conversation->title ?: 'Conversation #'.$conversation->id }}</h3>
                    <div class="card-tools">
                        <span class="badge badge-{{ $conversation->status === 'open' ? 'success' : 'secondary' }}">
                            {{ ucfirst($conversation->status) }}
                        </span>
                    </div>
                </div>
                <div class="card-body">
                    <dl class="row mb-0">
                        <dt class="col-sm-4">ID</dt>
                        <dd class="col-sm-8">{{ $conversation->id }}</dd>

                        <dt class="col-sm-4">Title</dt>
                        <dd class="col-sm-8">{{ $conversation->title ?: '—' }}</dd>

                        <dt class="col-sm-4">Source</dt>
                        <dd class="col-sm-8">
                            <span class="badge badge-secondary">{{ strtoupper($conversation->source) }}</span>
                        </dd>

                        <dt class="col-sm-4">Status</dt>
                        <dd class="col-sm-8">
                            <span class="badge badge-{{ $conversation->status === 'open' ? 'success' : 'secondary' }}">
                                {{ ucfirst($conversation->status) }}
                            </span>
                        </dd>

                        <dt class="col-sm-4">Messages</dt>
                        <dd class="col-sm-8">
                            <span class="badge badge-info">{{ $conversation->messages->count() }}</span>
                        </dd>

                        <dt class="col-sm-4">Last activity</dt>
                        <dd class="col-sm-8">
                            {{ $conversation->last_message_at?->format(config('app.datetime_format')) ?? '—' }}
                        </dd>

                        <dt class="col-sm-4">Created At</dt>
                        <dd class="col-sm-8">{{ $conversation->created_at?->format(config('app.datetime_format')) }}</dd>

                        <dt class="col-sm-4">Updated At</dt>
                        <dd class="col-sm-8">{{ $conversation->updated_at?->format(config('app.datetime_format')) }}</dd>
                    </dl>
                </div>
            </div>

            <div class="card card-secondary">
                <div class="card-header">
                    <h3 class="card-title">Messages</h3>
                </div>
                <div class="card-body p-0">
                    <div class="table-responsive">
                        <table class="table table-bordered table-striped mb-0 chatbot-messages-table">
                            <thead>
                                <tr>
                                    <th class="chatbot-col-id">ID</th>
                                    <th class="chatbot-col-role">Role</th>
                                    <th>Content</th>
                                    <th class="chatbot-col-sent">Sent At</th>
                                </tr>
                            </thead>
                            <tbody>
                                @forelse($conversation->messages as $message)
                                    <tr>
                                        <td>{{ $message->id }}</td>
                                        <td>
                                            @php
                                                $roleColors = [
                                                    'user' => 'primary',
                                                    'assistant' => 'success',
                                                    'system' => 'secondary',
                                                ];
                                                $roleColor = $roleColors[$message->role] ?? 'secondary';
                                            @endphp
                                            <span class="badge badge-{{ $roleColor }}">{{ ucfirst($message->role) }}</span>
                                        </td>
                                        <td>
                                            <div class="chatbot-message-content">{{ $message->content }}</div>
                                        </td>
                                        <td>
                                            <small>{{ $message->created_at->format(config('app.datetime_format')) }}</small>
                                        </td>
                                    </tr>
                                @empty
                                    <tr>
                                        <td colspan="4" class="text-center text-muted py-4">No messages in this conversation.</td>
                                    </tr>
                                @endforelse
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>
        </div>

        <div class="col-md-4">
            <div class="card card-info">
                <div class="card-header">
                    <h3 class="card-title">User</h3>
                </div>
                <div class="card-body">
                    @if($conversation->user)
                        <dl class="row mb-0">
                            <dt class="col-sm-5">User ID</dt>
                            <dd class="col-sm-7">{{ $conversation->user->id }}</dd>

                            <dt class="col-sm-5">Username</dt>
                            <dd class="col-sm-7">{{ $conversation->user->username ?? '—' }}</dd>

                            <dt class="col-sm-5">Email</dt>
                            <dd class="col-sm-7">{{ $conversation->user->email ?? '—' }}</dd>

                            <dt class="col-sm-5">Company</dt>
                            <dd class="col-sm-7">{{ $conversation->user->company?->name ?? '—' }}</dd>
                        </dl>
                        <a href="{{ route('admin.users.show', $conversation->user) }}" class="btn btn-sm btn-primary mt-2">
                            <i class="fas fa-user"></i> View User
                        </a>
                    @else
                        <p class="text-muted mb-0">
                            <i class="fas fa-info-circle"></i> No related user for this conversation.
                        </p>
                    @endif
                </div>
            </div>

            <div class="card">
                <div class="card-body">
                    <a href="{{ route('admin.chatbot.conversations.index') }}" class="btn btn-default btn-block">
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
