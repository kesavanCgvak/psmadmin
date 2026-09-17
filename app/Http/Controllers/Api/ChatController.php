<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\ListChatConversationsRequest;
use App\Http\Requests\ListChatMessagesRequest;
use App\Http\Requests\StoreChatConversationRequest;
use App\Http\Requests\StoreChatMessageRequest;
use App\Models\ChatConversation;
use App\Models\ChatMessage;
use App\Models\User;
use App\Services\ChatService;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;
use Tymon\JWTAuth\Facades\JWTAuth;

class ChatController extends Controller
{
    public function __construct(
        private readonly ChatService $chatService,
    ) {}

    /**
     * GET /api/chat/conversations
     */
    public function index(ListChatConversationsRequest $request): JsonResponse
    {
        $user = $this->authenticatedUser();
        $perPage = (int) ($request->validated()['per_page'] ?? config('app.admin_list_per_page', 25));

        $result = $this->chatService->listConversations($user, $perPage);

        return response()->json([
            'success' => true,
            'message' => 'Conversations fetched successfully.',
            'data' => $result['data'],
            'meta' => $result['meta'],
        ]);
    }

    /**
     * POST /api/chat/conversations
     */
    public function store(StoreChatConversationRequest $request): JsonResponse
    {
        $user = $this->authenticatedUser();

        try {
            $result = $this->chatService->findOrCreateCompanyConversation(
                $user,
                (int) $request->validated()['company_id']
            );
        } catch (ValidationException $e) {
            return response()->json([
                'success' => false,
                'message' => 'Validation error',
                'errors' => $e->errors(),
            ], 422);
        }

        return response()->json([
            'success' => true,
            'message' => $result['created']
                ? 'Conversation created successfully.'
                : 'Existing conversation returned.',
            'data' => $this->chatService->formatConversation($result['conversation'], $user),
        ], $result['created'] ? 201 : 200);
    }

    /**
     * GET /api/chat/conversations/{conversation}/messages
     */
    public function messages(ListChatMessagesRequest $request, ChatConversation $conversation): JsonResponse
    {
        $user = $this->authenticatedUser();
        if ($denied = $this->denyUnless($user, 'view', $conversation)) {
            return $denied;
        }

        $perPage = (int) ($request->validated()['per_page'] ?? config('app.admin_list_per_page', 25));
        $result = $this->chatService->listMessages($conversation, $user, $perPage);

        return response()->json([
            'success' => true,
            'message' => 'Messages fetched successfully.',
            'data' => $result['data'],
            'meta' => $result['meta'],
        ]);
    }

    /**
     * POST /api/chat/conversations/{conversation}/messages
     */
    public function sendMessage(StoreChatMessageRequest $request, ChatConversation $conversation): JsonResponse
    {
        $user = $this->authenticatedUser();
        if ($denied = $this->denyUnless($user, 'sendMessage', $conversation)) {
            return $denied;
        }

        $validated = $request->validated();
        $message = $this->chatService->sendMessage(
            $conversation,
            $user,
            $validated['message'],
            $validated['message_type'] ?? ChatMessage::TYPE_TEXT
        );

        return response()->json([
            'success' => true,
            'message' => 'Message sent.',
            'data' => $this->chatService->formatMessage($message, $user),
        ], 201);
    }

    /**
     * POST /api/chat/conversations/{conversation}/read
     */
    public function markRead(ChatConversation $conversation): JsonResponse
    {
        $user = $this->authenticatedUser();
        if ($denied = $this->denyUnless($user, 'markAsRead', $conversation)) {
            return $denied;
        }

        return response()->json([
            'success' => true,
            'message' => 'Conversation marked as read.',
            'data' => $this->chatService->markRead($conversation, $user),
        ]);
    }

    private function authenticatedUser(): User
    {
        $user = JWTAuth::parseToken()->authenticate();

        if (! $user) {
            abort(response()->json([
                'success' => false,
                'message' => 'Unauthorized user',
            ], 401));
        }

        return $user;
    }

    private function denyUnless(User $user, string $ability, ChatConversation $conversation): ?JsonResponse
    {
        if (Gate::forUser($user)->denies($ability, $conversation)) {
            return response()->json([
                'success' => false,
                'message' => 'Unauthorized.',
            ], 403);
        }

        return null;
    }
}
