@extends('adminlte::page')

@section('title', 'AI Chatbot')

@section('content_header')
    <h1>AI Chatbot</h1>
@stop

@section('css')
    @include('partials.responsive-css')
    <link rel="stylesheet" href="{{ asset('common/css/chatbot.css') }}">
@stop

@section('content')
    <div class="row">
        <div class="col-12 col-lg-8">
            <div class="card chatbot-card">
                <div class="card-header d-flex flex-wrap justify-content-between align-items-center">
                    <h3 class="card-title mb-2 mb-sm-0">Test chat</h3>
                    <button type="button" class="btn btn-outline-secondary btn-sm" id="chatbot-new-chat">
                        <i class="fas fa-plus"></i> New chat
                    </button>
                </div>
                <div class="card-body p-0">
                    <div id="chatbot-messages" class="chatbot-thread" aria-live="polite">
                        <div class="chatbot-bubble chatbot-bubble-assistant">
                            <div class="chatbot-bubble-content">
                                Hi — ask me about PSM using the knowledge base entries you have configured.
                            </div>
                        </div>
                    </div>
                </div>
                <div class="card-footer">
                    <form id="chatbot-form" class="chatbot-composer">
                        <label class="sr-only" for="chatbot-input">Message</label>
                        <textarea id="chatbot-input" class="form-control" rows="2" maxlength="4000"
                                  placeholder="Ask a question…" required></textarea>
                        <button type="submit" class="btn btn-primary" id="chatbot-send">
                            <i class="fas fa-paper-plane"></i> Send
                        </button>
                    </form>
                    <div id="chatbot-error" class="chatbot-error d-none" role="alert"></div>
                </div>
            </div>
        </div>
        <div class="col-12 col-lg-4">
            <div class="card">
                <div class="card-header">
                    <h3 class="card-title">Setup</h3>
                </div>
                <div class="card-body">
                    <p class="mb-2">
                        <strong>AI provider:</strong>
                        {{ strtoupper($aiSummary['provider']) }}
                        ({{ $aiSummary['model'] }})
                    </p>
                    <p class="mb-3">
                        <strong>API key:</strong>
                        @if($aiSummary['api_key_configured'])
                            <span class="badge badge-success">Configured</span>
                        @else
                            <span class="badge badge-danger">Missing</span>
                        @endif
                    </p>
                    <a href="{{ route('admin.chatbot.knowledge.index') }}" class="btn btn-outline-primary btn-sm btn-block mb-2">
                        Manage knowledge
                    </a>
                    <a href="{{ route('admin.chatbot.conversations.index') }}" class="btn btn-outline-secondary btn-sm btn-block">
                        View conversations
                    </a>
                </div>
            </div>
        </div>
    </div>
@stop

@section('js')
    <script>
        window.PSM_CHATBOT = {
            sendUrl: @json(route('admin.chatbot.send')),
            csrfToken: @json(csrf_token())
        };
    </script>
    <script src="{{ asset('common/js/chatbot.js') }}"></script>
@stop
