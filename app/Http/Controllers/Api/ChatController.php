<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\ListChatConversationsRequest;
use App\Http\Requests\ListChatMessagesRequest;
use App\Http\Requests\SearchChatRequest;
use App\Http\Requests\StoreChatConversationRequest;
use App\Http\Requests\StoreChatMessageRequest;
use App\Http\Requests\StoreChatTypingRequest;
use App\Http\Requests\UpdateChatNotificationSettingsRequest;
use App\Models\ChatConversation;
use App\Models\ChatMessage;
use App\Models\User;
use App\Services\ChatService;
use App\Support\ChatChannels;
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
        $validated = $request->validated();
        $perPage = (int) ($validated['per_page'] ?? config('app.admin_list_per_page', 25));

        $result = $this->chatService->listConversations($user, $perPage, $validated);

        return response()->json([
            'success' => true,
            'message' => 'Conversations fetched successfully.',
            'data' => $result['data'],
            'meta' => $result['meta'],
        ]);
    }

    /**
     * GET /api/chat/conversations/{conversation}
     */
    public function show(ChatConversation $conversation): JsonResponse
    {
        $user = $this->authenticatedUser();
        if ($denied = $this->denyUnless($user, 'view', $conversation)) {
            return $denied;
        }

        return response()->json([
            'success' => true,
            'message' => 'Conversation fetched successfully.',
            'data' => $this->chatService->formatConversation($conversation, $user),
        ]);
    }

    /**
     * POST /api/chat/conversations
     */
    public function store(StoreChatConversationRequest $request): JsonResponse
    {
        $user = $this->authenticatedUser();
        $validated = $request->validated();

        try {
            $result = $this->chatService->findOrCreateCompanyConversation(
                $user,
                (int) $validated['company_id'],
                isset($validated['rental_job_id']) ? (int) $validated['rental_job_id'] : null
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
            'data' => $this->chatService->formatMessage($message, $user, $conversation),
        ], 201);
    }

    /**
     * DELETE /api/chat/conversations/{conversation}/messages/{message}
     */
    public function destroyMessage(ChatConversation $conversation, ChatMessage $message): JsonResponse
    {
        $user = $this->authenticatedUser();
        if ($denied = $this->denyUnless($user, 'view', $conversation)) {
            return $denied;
        }
        if (Gate::forUser($user)->denies('delete', $message)) {
            return response()->json([
                'success' => false,
                'message' => 'Unauthorized.',
            ], 403);
        }

        $deleted = $this->chatService->deleteMessage($conversation, $message, $user);

        return response()->json([
            'success' => true,
            'message' => 'Message deleted.',
            'data' => $this->chatService->formatMessage($deleted, $user, $conversation),
        ]);
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

    /**
     * POST /api/chat/conversations/{conversation}/archive
     */
    public function archive(ChatConversation $conversation): JsonResponse
    {
        $user = $this->authenticatedUser();
        if ($denied = $this->denyUnless($user, 'archive', $conversation)) {
            return $denied;
        }

        return response()->json([
            'success' => true,
            'message' => 'Conversation archived.',
            'data' => $this->chatService->archive($conversation, $user),
        ]);
    }

    /**
     * POST /api/chat/conversations/{conversation}/unarchive
     */
    public function unarchive(ChatConversation $conversation): JsonResponse
    {
        $user = $this->authenticatedUser();
        if ($denied = $this->denyUnless($user, 'unarchive', $conversation)) {
            return $denied;
        }

        return response()->json([
            'success' => true,
            'message' => 'Conversation restored.',
            'data' => $this->chatService->unarchive($conversation, $user),
        ]);
    }

    /**
     * GET /api/chat/search
     */
    public function search(SearchChatRequest $request): JsonResponse
    {
        $user = $this->authenticatedUser();
        $validated = $request->validated();
        $perPage = (int) ($validated['per_page'] ?? 25);

        $result = $this->chatService->search($user, $validated['q'], $perPage);

        return response()->json([
            'success' => true,
            'message' => 'Search completed.',
            'data' => [
                'conversations' => $result['conversations'],
                'messages' => $result['messages'],
            ],
            'meta' => $result['meta'],
        ]);
    }

    /**
     * GET /api/chat/unread-count
     */
    public function unreadCount(): JsonResponse
    {
        $user = $this->authenticatedUser();

        return response()->json([
            'success' => true,
            'data' => $this->chatService->unreadSummaryFor($user),
        ]);
    }

    /**
     * GET /api/chat/notification-settings
     */
    public function notificationSettings(): JsonResponse
    {
        $user = $this->authenticatedUser();

        return response()->json([
            'success' => true,
            'data' => $this->chatService->notificationSettings($user),
        ]);
    }

    /**
     * PUT /api/chat/notification-settings
     */
    public function updateNotificationSettings(UpdateChatNotificationSettingsRequest $request): JsonResponse
    {
        $user = $this->authenticatedUser();

        return response()->json([
            'success' => true,
            'message' => 'Notification settings updated.',
            'data' => $this->chatService->updateNotificationSettings(
                $user,
                (bool) $request->validated()['browser_notifications_enabled']
            ),
        ]);
    }

    /**
     * GET /api/chat/realtime-config
     */
    public function realtimeConfig(): JsonResponse
    {
        $user = $this->authenticatedUser();
        $settings = $this->chatService->notificationSettings($user);

        $connection = (string) config('broadcasting.default');
        $reverb = config('broadcasting.connections.reverb', []);
        $enabled = $connection === 'reverb' && filled($reverb['key'] ?? null);

        return response()->json([
            'success' => true,
            'data' => [
                'enabled' => $enabled,
                'broadcaster' => 'reverb',
                'key' => $enabled ? ($reverb['key'] ?? null) : null,
                'host' => $reverb['options']['host'] ?? null,
                'port' => isset($reverb['options']['port']) ? (int) $reverb['options']['port'] : null,
                'scheme' => $reverb['options']['scheme'] ?? null,
                'auth_endpoint' => '/api/broadcasting/auth',
                'channels' => [
                    'conversation' => 'chat.conversation.{id}',
                    'company' => 'chat.company.{id}',
                    'online' => ChatChannels::online(),
                ],
                'events' => [
                    'message_sent' => 'chat.message.sent',
                    'message_deleted' => 'chat.message.deleted',
                    'messages_read' => 'chat.messages.read',
                    'user_typing' => 'chat.user.typing',
                ],
                'browser_notifications' => [
                    'enabled' => $settings['browser_notifications_enabled'],
                    'permission_must_be_granted_in_browser' => true,
                ],
            ],
        ]);
    }

    /**
     * POST /api/chat/conversations/{conversation}/typing
     */
    public function typing(StoreChatTypingRequest $request, ChatConversation $conversation): JsonResponse
    {
        $user = $this->authenticatedUser();
        if ($denied = $this->denyUnless($user, 'type', $conversation)) {
            return $denied;
        }

        $this->chatService->setTyping(
            $conversation,
            $user,
            (bool) $request->validated()['is_typing']
        );

        return response()->json([
            'success' => true,
            'message' => 'Typing status broadcast.',
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
