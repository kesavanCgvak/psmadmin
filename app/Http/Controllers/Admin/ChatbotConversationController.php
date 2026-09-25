<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\ChatbotConversation;
use Illuminate\Http\Request;

class ChatbotConversationController extends Controller
{
    public function index(Request $request)
    {
        $query = ChatbotConversation::query()
            ->with('user')
            ->withCount('messages')
            ->orderByDesc('last_message_at')
            ->orderByDesc('id');

        if ($request->filled('source')) {
            $query->where('source', $request->string('source')->toString());
        }

        if ($request->filled('status')) {
            $query->where('status', $request->string('status')->toString());
        }

        $conversations = $query
            ->paginate(config('app.admin_list_per_page'))
            ->withQueryString();

        return view('admin.chatbot.conversations.index', compact('conversations'));
    }

    public function show(ChatbotConversation $conversation)
    {
        $conversation->load(['user', 'messages']);

        return view('admin.chatbot.conversations.show', compact('conversation'));
    }
}
