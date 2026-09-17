<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\ChatbotConversation;
use App\Models\ChatbotKnowledge;
use App\Services\Chatbot\ChatbotService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Throwable;
use Tymon\JWTAuth\Facades\JWTAuth;

class ChatbotController extends Controller
{
    public function __construct(
        private readonly ChatbotService $chatbotService,
    ) {}

    public function start(Request $request): JsonResponse
    {
        $user = $this->authenticatedUser();
        if ($user instanceof JsonResponse) {
            return $user;
        }

        $conversation = $this->chatbotService->startConversation(
            $user,
            ChatbotConversation::SOURCE_API,
        );

        return response()->json([
            'success' => true,
            'message' => 'Conversation started.',
            'data' => [
                'conversation_id' => $conversation->id,
                'status' => $conversation->status,
            ],
        ], 201);
    }

    public function message(Request $request): JsonResponse
    {
        $user = $this->authenticatedUser();
        if ($user instanceof JsonResponse) {
            return $user;
        }

        $validated = $request->validate([
            'message' => 'required|string|max:4000',
            'conversation_id' => 'nullable|integer|exists:chatbot_conversations,id',
        ]);

        try {
            if (!empty($validated['conversation_id'])) {
                $conversation = ChatbotConversation::query()->findOrFail($validated['conversation_id']);

                if ((int) $conversation->user_id !== (int) $user->id) {
                    return response()->json([
                        'success' => false,
                        'message' => 'Conversation not found.',
                    ], 404);
                }
            } else {
                $conversation = $this->chatbotService->startConversation(
                    $user,
                    ChatbotConversation::SOURCE_API,
                );
            }

            $result = $this->chatbotService->sendMessage(
                $conversation,
                $validated['message'],
                $user,
            );

            return response()->json([
                'success' => true,
                'message' => 'Reply generated.',
                'data' => [
                    'conversation_id' => $result['conversation']->id,
                    'reply' => $result['assistant_message']->content,
                    'user_message' => [
                        'id' => $result['user_message']->id,
                        'role' => $result['user_message']->role,
                        'content' => $result['user_message']->content,
                        'created_at' => optional($result['user_message']->created_at)->toIso8601String(),
                    ],
                    'assistant_message' => [
                        'id' => $result['assistant_message']->id,
                        'role' => $result['assistant_message']->role,
                        'content' => $result['assistant_message']->content,
                        'created_at' => optional($result['assistant_message']->created_at)->toIso8601String(),
                    ],
                ],
            ]);
        } catch (Throwable $e) {
            report($e);

            return response()->json([
                'success' => false,
                'message' => 'Unable to process chatbot message right now.',
            ], 500);
        }
    }

    public function conversations(Request $request): JsonResponse
    {
        $user = $this->authenticatedUser();
        if ($user instanceof JsonResponse) {
            return $user;
        }

        $conversations = ChatbotConversation::query()
            ->where('user_id', $user->id)
            ->where('source', ChatbotConversation::SOURCE_API)
            ->orderByDesc('last_message_at')
            ->orderByDesc('id')
            ->paginate((int) config('app.admin_list_per_page', 25));

        return response()->json([
            'success' => true,
            'message' => 'Conversations retrieved.',
            'data' => $conversations->getCollection()->map(fn (ChatbotConversation $conversation) => [
                'id' => $conversation->id,
                'title' => $conversation->title,
                'status' => $conversation->status,
                'last_message_at' => optional($conversation->last_message_at)->toIso8601String(),
                'created_at' => optional($conversation->created_at)->toIso8601String(),
            ]),
            'meta' => [
                'current_page' => $conversations->currentPage(),
                'last_page' => $conversations->lastPage(),
                'per_page' => $conversations->perPage(),
                'total' => $conversations->total(),
            ],
        ]);
    }

    public function messages(Request $request, int $conversationId): JsonResponse
    {
        $user = $this->authenticatedUser();
        if ($user instanceof JsonResponse) {
            return $user;
        }

        $conversation = ChatbotConversation::query()
            ->where('id', $conversationId)
            ->where('user_id', $user->id)
            ->first();

        if (!$conversation) {
            return response()->json([
                'success' => false,
                'message' => 'Conversation not found.',
            ], 404);
        }

        $messages = $conversation->messages()
            ->orderBy('id')
            ->get()
            ->map(fn ($message) => [
                'id' => $message->id,
                'role' => $message->role,
                'content' => $message->content,
                'created_at' => optional($message->created_at)->toIso8601String(),
            ]);

        return response()->json([
            'success' => true,
            'message' => 'Messages retrieved.',
            'data' => [
                'conversation_id' => $conversation->id,
                'title' => $conversation->title,
                'messages' => $messages,
            ],
        ]);
    }

    public function knowledge(Request $request): JsonResponse
    {
        $entries = ChatbotKnowledge::query()
            ->active()
            ->orderBy('sort_order')
            ->orderBy('title')
            ->get(['id', 'title', 'category', 'question', 'content', 'sort_order']);

        return response()->json([
            'success' => true,
            'message' => 'Knowledge entries retrieved.',
            'data' => $entries,
        ]);
    }

    private function authenticatedUser(): mixed
    {
        try {
            $user = JWTAuth::parseToken()->authenticate();
        } catch (Throwable) {
            return response()->json([
                'success' => false,
                'message' => 'User not authenticated',
            ], 401);
        }

        if (!$user) {
            return response()->json([
                'success' => false,
                'message' => 'User not authenticated',
            ], 401);
        }

        return $user;
    }
}
