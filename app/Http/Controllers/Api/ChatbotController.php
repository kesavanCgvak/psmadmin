<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\ChatbotConversation;
use App\Models\ChatbotKnowledge;
use App\Models\User;
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
        $user = $this->optionalUser();

        $conversation = $this->chatbotService->startConversation(
            $user,
            ChatbotConversation::SOURCE_API,
            $user === null,
        );

        return response()->json([
            'success' => true,
            'message' => 'Conversation started.',
            'data' => $this->conversationPayload($conversation),
        ], 201);
    }

    public function message(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'message' => 'required|string|max:4000',
            'conversation_id' => 'nullable|integer|exists:chatbot_conversations,id',
            'guest_token' => 'nullable|uuid',
        ]);

        $user = $this->optionalUser();

        try {
            if (!empty($validated['conversation_id'])) {
                $conversation = ChatbotConversation::query()->findOrFail($validated['conversation_id']);

                if (!$this->canAccessConversation($conversation, $user, $validated['guest_token'] ?? null)) {
                    return response()->json([
                        'success' => false,
                        'message' => 'Conversation not found.',
                    ], 404);
                }
            } else {
                $conversation = $this->chatbotService->startConversation(
                    $user,
                    ChatbotConversation::SOURCE_API,
                    $user === null,
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
                    ...$this->conversationPayload($result['conversation']),
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
        $user = $this->optionalUser();

        if (!$user) {
            return response()->json([
                'success' => false,
                'message' => 'Login required to list conversations. Guests should keep conversation_id and guest_token from the first reply.',
            ], 401);
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
        $validated = $request->validate([
            'guest_token' => 'nullable|uuid',
        ]);

        $user = $this->optionalUser();

        $conversation = ChatbotConversation::query()->find($conversationId);

        if (!$conversation) {
            return response()->json([
                'success' => false,
                'message' => 'Conversation not found.',
            ], 404);
        }

        if ($conversation->user_id === null && empty($validated['guest_token'])) {
            return response()->json([
                'success' => false,
                'message' => 'guest_token is required for guest conversations. Pass it as a query parameter.',
            ], 422);
        }

        if (!$this->canAccessConversation($conversation, $user, $validated['guest_token'] ?? null)) {
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
                ...$this->conversationPayload($conversation),
                'title' => $conversation->title,
                'messages' => $messages,
            ],
        ]);
    }

    public function knowledge(): JsonResponse
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

    private function optionalUser(): ?User
    {
        try {
            if (!JWTAuth::getToken()) {
                return null;
            }

            $user = JWTAuth::parseToken()->authenticate();

            return $user ?: null;
        } catch (Throwable) {
            return null;
        }
    }

    private function canAccessConversation(
        ChatbotConversation $conversation,
        ?User $user,
        ?string $guestToken,
    ): bool {
        if ($user && (int) $conversation->user_id === (int) $user->id) {
            return true;
        }

        if ($conversation->user_id === null
            && $conversation->guest_token
            && $guestToken
            && hash_equals($conversation->guest_token, $guestToken)
        ) {
            return true;
        }

        return false;
    }

    /**
     * @return array{conversation_id: int, guest_token: ?string, status: string}
     */
    private function conversationPayload(ChatbotConversation $conversation): array
    {
        return [
            'conversation_id' => $conversation->id,
            'guest_token' => $conversation->guest_token,
            'status' => $conversation->status,
        ];
    }
}
