<?php

namespace App\Services;

use App\Events\ChatMessageDeleted;
use App\Events\ChatMessageSent;
use App\Events\ChatMessagesRead;
use App\Events\ChatUserTyping;
use App\Jobs\ProcessChatMessageNotificationsJob;
use App\Models\ChatConversation;
use App\Models\ChatConversationUserState;
use App\Models\ChatMessage;
use App\Models\ChatUserSetting;
use App\Models\Company;
use App\Models\User;
use App\Support\ChatIdentity;
use App\Support\ChatLog;
use App\Support\DefaultImagePath;
use App\Support\UserPresence;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\QueryException;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\ValidationException;
use Throwable;

class ChatService
{
    /**
     * Find or create a company-to-company conversation.
     * Pass rentalJobId to use (or create) the job-specific thread; omit for the general thread.
     *
     * @return array{conversation: ChatConversation, created: bool}
     */
    public function findOrCreateCompanyConversation(User $user, int $otherCompanyId, ?int $rentalJobId = null): array
    {
        $userCompanyId = (int) ($user->company_id ?? 0);
        if ($userCompanyId <= 0) {
            throw ValidationException::withMessages([
                'company_id' => ['User does not belong to any company.'],
            ]);
        }

        if ($userCompanyId === $otherCompanyId) {
            throw ValidationException::withMessages([
                'company_id' => ['You cannot start a conversation with your own company.'],
            ]);
        }

        $otherCompany = Company::query()->find($otherCompanyId);
        if (! $otherCompany) {
            throw ValidationException::withMessages([
                'company_id' => ['The selected company does not exist.'],
            ]);
        }

        if ($rentalJobId !== null && $rentalJobId > 0 && Schema::hasTable('rental_jobs')) {
            $jobExists = DB::table('rental_jobs')->where('id', $rentalJobId)->exists();
            if (! $jobExists) {
                throw ValidationException::withMessages([
                    'rental_job_id' => ['The selected rental job does not exist.'],
                ]);
            }
        } else {
            $rentalJobId = null;
        }

        [$companyAId, $companyBId] = ChatConversation::orderedCompanyIds($userCompanyId, $otherCompanyId);
        $pairKey = ChatConversation::pairKeyFor($userCompanyId, $otherCompanyId, $rentalJobId);

        try {
            return DB::transaction(function () use ($user, $companyAId, $companyBId, $pairKey, $rentalJobId) {
                $existingQuery = ChatConversation::query()
                    ->where('pair_key', $pairKey)
                    ->lockForUpdate();

                if ($rentalJobId === null) {
                    $existingQuery->whereNull('rental_job_id');
                } else {
                    $existingQuery->where('rental_job_id', $rentalJobId);
                }

                $existing = $existingQuery->first();

                if ($existing) {
                    return [
                        'conversation' => $this->loadConversation($existing),
                        'created' => false,
                    ];
                }

                $conversation = ChatConversation::create([
                    'company_a_id' => $companyAId,
                    'company_b_id' => $companyBId,
                    'created_by_user_id' => $user->id,
                    'rental_job_id' => $rentalJobId,
                    'pair_key' => $pairKey,
                ]);

                return [
                    'conversation' => $this->loadConversation($conversation),
                    'created' => true,
                ];
            });
        } catch (QueryException $e) {
            $existingQuery = ChatConversation::query()->where('pair_key', $pairKey);
            if ($rentalJobId === null) {
                $existingQuery->whereNull('rental_job_id');
            } else {
                $existingQuery->where('rental_job_id', $rentalJobId);
            }

            $existing = $existingQuery->first();

            if ($existing) {
                return [
                    'conversation' => $this->loadConversation($existing),
                    'created' => false,
                ];
            }

            throw $e;
        }
    }

    /**
     * @param  array{archived?: bool, include_archived?: bool, general_only?: bool, rental_job_id?: int}  $filters
     * @return array{data: list<array<string, mixed>>, meta: array<string, int>}
     */
    public function listConversations(User $user, int $perPage, array $filters = []): array
    {
        $companyId = (int) ($user->company_id ?? 0);
        if ($companyId <= 0) {
            return [
                'data' => [],
                'meta' => [
                    'page' => 1,
                    'per_page' => $perPage,
                    'total' => 0,
                    'total_pages' => 0,
                    'total_unread' => 0,
                ],
            ];
        }

        $query = $this->companyConversationsQuery($companyId);
        $this->applyConversationFilters($query, $user, $filters);

        $paginator = $query
            ->with([
                'latestMessage.sender.profile',
                'companyA',
                'companyB',
                'rentalJob',
                'userStates' => fn ($states) => $states->where('user_id', $user->id),
            ])
            ->orderByDesc('updated_at')
            ->orderByDesc('id')
            ->paginate($perPage);

        $conversationIds = $paginator->getCollection()->pluck('id')->all();
        $unreadCounts = $this->unreadCountsFor($user, $conversationIds);

        $otherCompanyIds = $paginator->getCollection()
            ->map(fn (ChatConversation $conversation) => $conversation->otherCompanyId($companyId))
            ->filter()
            ->all();
        $onlineCompanyIds = array_fill_keys(UserPresence::onlineCompanyIds($otherCompanyIds), true);

        $data = $paginator->getCollection()
            ->map(fn (ChatConversation $conversation) => $this->formatConversation(
                $conversation,
                $user,
                (int) ($unreadCounts[$conversation->id] ?? 0),
                $onlineCompanyIds
            ))
            ->values()
            ->all();

        $unreadSummary = $this->unreadSummaryFor($user);

        return [
            'data' => $data,
            'meta' => array_merge($this->meta($paginator), [
                'total_unread' => $unreadSummary['total_unread'],
            ]),
        ];
    }

    /**
     * @return array{total_unread: int, conversations_with_unread: int}
     */
    public function unreadSummaryFor(User $user): array
    {
        $companyId = (int) ($user->company_id ?? 0);
        if ($companyId <= 0) {
            return [
                'total_unread' => 0,
                'conversations_with_unread' => 0,
            ];
        }

        $counts = $this->unreadCountsFor($user, $this->companyConversationIds($companyId));

        return [
            'total_unread' => (int) array_sum($counts),
            'conversations_with_unread' => count($counts),
        ];
    }

    /**
     * @return array{data: list<array<string, mixed>>, meta: array<string, int>}
     */
    public function listMessages(ChatConversation $conversation, User $user, int $perPage): array
    {
        $this->markIncomingMessagesDelivered($conversation, $user);

        $paginator = ChatMessage::query()
            ->withTrashed()
            ->where('conversation_id', $conversation->id)
            ->with('sender.profile')
            ->orderByDesc('created_at')
            ->orderByDesc('id')
            ->paginate($perPage);

        $receiptContext = $this->receiptContext($conversation, $user);

        $data = $paginator->getCollection()
            ->map(fn (ChatMessage $message) => $this->formatMessage($message, $user, $conversation, $receiptContext))
            ->values()
            ->all();

        return [
            'data' => $data,
            'meta' => $this->meta($paginator),
        ];
    }

    public function sendMessage(
        ChatConversation $conversation,
        User $sender,
        string $message,
        string $messageType = ChatMessage::TYPE_TEXT
    ): ChatMessage {
        $senderCompanyId = (int) ($sender->company_id ?? 0);
        if ($senderCompanyId <= 0 || ! $conversation->involvesCompany($senderCompanyId)) {
            abort(response()->json([
                'success' => false,
                'message' => 'Unauthorized.',
            ], 403));
        }

        $recipientCompanyId = (int) $conversation->otherCompanyId($senderCompanyId);
        $recipientOnline = $recipientCompanyId > 0
            && in_array($recipientCompanyId, UserPresence::onlineCompanyIds([$recipientCompanyId]), true);

        $chatMessage = DB::transaction(function () use ($conversation, $sender, $senderCompanyId, $message, $messageType, $recipientOnline) {
            $chatMessage = ChatMessage::create([
                'conversation_id' => $conversation->id,
                'sender_user_id' => $sender->id,
                'sender_company_id' => $senderCompanyId,
                'message' => $message,
                'message_type' => $messageType,
                'delivered_at' => $recipientOnline ? now() : null,
            ]);

            $conversation->touch();
            $this->unarchiveForUser($conversation, $sender);

            return $chatMessage;
        });

        $this->unarchiveForCompanyUsers($conversation, $recipientCompanyId);

        $chatMessage->load('sender.profile');
        $sender->loadMissing('profile');
        $conversation->loadMissing(['companyA', 'companyB']);

        $recipientCompany = $conversation->otherCompany($senderCompanyId);
        $recipientUserIds = $this->recipientUserIds($recipientCompanyId, (int) $sender->id);
        $defaultContactUserId = $recipientCompany?->default_contact_id
            ? (int) $recipientCompany->default_contact_id
            : null;

        ChatLog::info('[CHAT] Message sent', [
            'user_id' => $sender->id,
            'company_id' => $senderCompanyId,
            'conversation_id' => $conversation->id,
            'message_id' => $chatMessage->id,
            'recipient_company_id' => $recipientCompanyId,
            'recipient_online' => $recipientOnline,
        ]);

        $event = new ChatMessageSent(
            message: $chatMessage,
            conversation: $conversation,
            sender: $sender,
            senderCompanyId: $senderCompanyId,
            recipientCompanyId: $recipientCompanyId,
            recipientUserIds: $recipientUserIds,
            defaultContactUserId: $defaultContactUserId,
        );
        $event->dontBroadcastToCurrentUser();

        ChatLog::info('[CHAT-REALTIME] Dispatching broadcast event', [
            'event' => $event->broadcastAs(),
            'event_class' => $event::class,
            'conversation_id' => $conversation->id,
            'message_id' => $chatMessage->id,
            'user_id' => $sender->id,
            'channels' => ChatLog::channelNames($event->broadcastOn()),
        ]);

        $failuresBefore = ChatLog::broadcastFailureCount();

        try {
            event($event);
        } catch (Throwable $e) {
            ChatLog::broadcastFailed($event->broadcastAs(), $e, [
                'conversation_id' => $conversation->id,
                'message_id' => $chatMessage->id,
                'user_id' => $sender->id,
                'company_id' => $senderCompanyId,
            ]);

            throw $e;
        }

        ChatLog::info('[CHAT-REVERB] Broadcast dispatch completed', [
            'event' => $event->broadcastAs(),
            'event_class' => $event::class,
            'conversation_id' => $conversation->id,
            'message_id' => $chatMessage->id,
            'user_id' => $sender->id,
            'succeeded' => ChatLog::broadcastFailureCount() === $failuresBefore,
            'connection' => config('broadcasting.default'),
            'reverb_target' => ChatLog::reverbTarget(),
        ]);

        try {
            ProcessChatMessageNotificationsJob::dispatch(
                (int) $chatMessage->id,
                (int) $conversation->id,
                (int) $sender->id,
            );
        } catch (Throwable $e) {
            ChatLog::error('Failed to queue chat notifications', [
                'message_id' => $chatMessage->id,
                'conversation_id' => $conversation->id,
                'sender_user_id' => $sender->id,
                'exception' => $e->getMessage(),
            ]);
        }

        return $chatMessage;
    }

    public function deleteMessage(ChatConversation $conversation, ChatMessage $message, User $user): ChatMessage
    {
        if ((int) $message->conversation_id !== (int) $conversation->id) {
            abort(response()->json([
                'success' => false,
                'message' => 'Message not found.',
            ], 404));
        }

        $message->deleted_by_user_id = $user->id;
        $message->save();
        $message->delete();

        $senderCompanyId = (int) $message->sender_company_id;
        $recipientCompanyId = (int) $conversation->otherCompanyId($senderCompanyId);

        $event = new ChatMessageDeleted(
            message: $message,
            conversation: $conversation,
            deletedBy: $user,
            senderCompanyId: $senderCompanyId,
            recipientCompanyId: $recipientCompanyId,
        );
        $event->dontBroadcastToCurrentUser();
        event($event);

        return $message;
    }

    public function setTyping(ChatConversation $conversation, User $user, bool $isTyping): void
    {
        $user->loadMissing('profile');

        ChatLog::info('[CHAT-TYPING] Typing state changed', [
            'user_id' => $user->id,
            'company_id' => $user->company_id,
            'conversation_id' => $conversation->id,
            'is_typing' => $isTyping,
        ]);

        $event = new ChatUserTyping(
            conversation: $conversation,
            user: $user,
            isTyping: $isTyping,
        );
        $event->dontBroadcastToCurrentUser();

        ChatLog::info('[CHAT-REALTIME] Dispatching broadcast event', [
            'event' => $event->broadcastAs(),
            'event_class' => $event::class,
            'conversation_id' => $conversation->id,
            'user_id' => $user->id,
            'channels' => ChatLog::channelNames($event->broadcastOn()),
        ]);

        $failuresBefore = ChatLog::broadcastFailureCount();

        try {
            event($event);
        } catch (Throwable $e) {
            ChatLog::broadcastFailed($event->broadcastAs(), $e, [
                'conversation_id' => $conversation->id,
                'user_id' => $user->id,
                'company_id' => $user->company_id,
            ]);

            throw $e;
        }

        ChatLog::info('[CHAT-REVERB] Broadcast dispatch completed', [
            'event' => $event->broadcastAs(),
            'event_class' => $event::class,
            'conversation_id' => $conversation->id,
            'user_id' => $user->id,
            'succeeded' => ChatLog::broadcastFailureCount() === $failuresBefore,
            'connection' => config('broadcasting.default'),
            'reverb_target' => ChatLog::reverbTarget(),
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    public function markRead(ChatConversation $conversation, User $user): array
    {
        $state = ChatConversationUserState::query()->firstOrNew([
            'conversation_id' => $conversation->id,
            'user_id' => $user->id,
        ]);
        $state->last_read_at = now();
        $state->save();

        $this->markIncomingMessagesDelivered($conversation, $user);

        $user->loadMissing('profile');
        $readerCompanyId = (int) ($user->company_id ?? 0);
        $otherCompanyId = (int) ($conversation->otherCompanyId($readerCompanyId) ?? 0);

        $event = new ChatMessagesRead(
            conversation: $conversation,
            reader: $user,
            lastReadAt: $state->last_read_at->toIso8601String(),
            readerCompanyId: $readerCompanyId,
            otherCompanyId: $otherCompanyId,
        );
        $event->dontBroadcastToCurrentUser();
        event($event);

        $unreadCounts = $this->unreadCountsFor($user, [$conversation->id]);

        return [
            'conversation_id' => $conversation->id,
            'last_read_at' => $state->last_read_at?->toIso8601String(),
            'unread_count' => (int) ($unreadCounts[$conversation->id] ?? 0),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function archive(ChatConversation $conversation, User $user): array
    {
        $state = ChatConversationUserState::query()->firstOrNew([
            'conversation_id' => $conversation->id,
            'user_id' => $user->id,
        ]);
        $state->archived_at = now();
        $state->save();

        $conversation->loadMissing(['latestMessage.sender.profile', 'companyA', 'companyB', 'rentalJob']);
        $conversation->setRelation('userStates', collect([$state]));

        return $this->formatConversation($conversation, $user);
    }

    /**
     * @return array<string, mixed>
     */
    public function unarchive(ChatConversation $conversation, User $user): array
    {
        $this->unarchiveForUser($conversation, $user);

        return $this->formatConversation($this->loadConversation($conversation, $user), $user);
    }

    /**
     * @return array{conversations: list<array<string, mixed>>, messages: list<array<string, mixed>>, meta: array<string, int|string>}
     */
    public function search(User $user, string $term, int $perPage): array
    {
        $companyId = (int) ($user->company_id ?? 0);
        if ($companyId <= 0) {
            return [
                'conversations' => [],
                'messages' => [],
                'meta' => [
                    'query' => $term,
                    'conversations_total' => 0,
                    'messages_total' => 0,
                    'per_page' => $perPage,
                ],
            ];
        }

        $like = '%'.$this->escapeLike($term).'%';
        $conversationIds = $this->companyConversationIds($companyId);

        $conversationPaginator = $this->searchConversations($user, $companyId, $like, $perPage);
        $conversationUnread = $this->unreadCountsFor($user, $conversationPaginator->getCollection()->pluck('id')->all());
        $otherCompanyIds = $conversationPaginator->getCollection()
            ->map(fn (ChatConversation $conversation) => $conversation->otherCompanyId($companyId))
            ->filter()
            ->all();
        $onlineCompanyIds = array_fill_keys(UserPresence::onlineCompanyIds($otherCompanyIds), true);

        $conversations = $conversationPaginator->getCollection()
            ->map(fn (ChatConversation $conversation) => $this->formatConversation(
                $conversation,
                $user,
                (int) ($conversationUnread[$conversation->id] ?? 0),
                $onlineCompanyIds
            ))
            ->values()
            ->all();

        $messagePaginator = ChatMessage::query()
            ->whereIn('conversation_id', $conversationIds ?: [0])
            ->whereNull('deleted_at')
            ->where(function (Builder $query) use ($like) {
                $query->where('chat_messages.message', 'like', $like)
                    ->orWhereHas('sender.profile', function (Builder $profile) use ($like) {
                        $profile->where('full_name', 'like', $like)
                            ->orWhere('first_name', 'like', $like)
                            ->orWhere('last_name', 'like', $like);
                    })
                    ->orWhereHas('sender', function (Builder $sender) use ($like) {
                        $sender->where('username', 'like', $like);
                    });
            })
            ->with(['sender.profile', 'conversation.companyA', 'conversation.companyB'])
            ->orderByDesc('created_at')
            ->orderByDesc('id')
            ->paginate($perPage);

        $messages = $messagePaginator->getCollection()
            ->map(function (ChatMessage $message) use ($user) {
                $conversation = $message->conversation;
                $payload = $this->formatMessage($message, $user, $conversation);
                $otherCompany = $conversation?->otherCompany((int) ($user->company_id ?? 0));
                $payload['other_company_id'] = $otherCompany?->id;
                $payload['other_company_name'] = $otherCompany?->name;

                return $payload;
            })
            ->values()
            ->all();

        return [
            'conversations' => $conversations,
            'messages' => $messages,
            'meta' => [
                'query' => $term,
                'conversations_total' => $conversationPaginator->total(),
                'messages_total' => $messagePaginator->total(),
                'per_page' => $perPage,
            ],
        ];
    }

    /**
     * @return array{browser_notifications_enabled: bool, email_notifications_enabled: bool, sms_notifications_enabled: bool, sms_consented: bool}
     */
    public function notificationSettings(User $user): array
    {
        $settings = ChatUserSetting::query()->firstOrCreate(
            ['user_id' => $user->id],
            [
                'browser_notifications_enabled' => (bool) config('chat.defaults.browser_notifications_enabled', false),
                'email_notifications_enabled' => (bool) config('chat.defaults.email_notifications_enabled', true),
                'sms_notifications_enabled' => (bool) config('chat.defaults.sms_notifications_enabled', false),
            ]
        );

        return $this->formatNotificationSettings($settings);
    }

    /**
     * @param  array{browser_notifications_enabled?: bool, email_notifications_enabled?: bool, sms_notifications_enabled?: bool}  $input
     * @return array{browser_notifications_enabled: bool, email_notifications_enabled: bool, sms_notifications_enabled: bool, sms_consented: bool}
     */
    public function updateNotificationSettings(User $user, array $input): array
    {
        $settings = ChatUserSetting::query()->firstOrNew(['user_id' => $user->id]);

        if (! $settings->exists) {
            $settings->browser_notifications_enabled = (bool) config('chat.defaults.browser_notifications_enabled', false);
            $settings->email_notifications_enabled = (bool) config('chat.defaults.email_notifications_enabled', true);
            $settings->sms_notifications_enabled = (bool) config('chat.defaults.sms_notifications_enabled', false);
        }

        if (array_key_exists('browser_notifications_enabled', $input)) {
            $settings->browser_notifications_enabled = (bool) $input['browser_notifications_enabled'];
        }

        if (array_key_exists('email_notifications_enabled', $input)) {
            $settings->email_notifications_enabled = (bool) $input['email_notifications_enabled'];
        }

        if (array_key_exists('sms_notifications_enabled', $input)) {
            $enabled = (bool) $input['sms_notifications_enabled'];
            $settings->sms_notifications_enabled = $enabled;
            if ($enabled && $settings->sms_consented_at === null) {
                $settings->sms_consented_at = now();
            }
        }

        $settings->save();

        return $this->formatNotificationSettings($settings);
    }

    /**
     * @return array{browser_notifications_enabled: bool, email_notifications_enabled: bool, sms_notifications_enabled: bool, sms_consented: bool}
     */
    private function formatNotificationSettings(ChatUserSetting $settings): array
    {
        return [
            'browser_notifications_enabled' => (bool) $settings->browser_notifications_enabled,
            'email_notifications_enabled' => (bool) $settings->email_notifications_enabled,
            'sms_notifications_enabled' => (bool) $settings->sms_notifications_enabled,
            'sms_consented' => $settings->sms_consented_at !== null,
        ];
    }

    /**
     * @param  array<int, true>|null  $onlineCompanyIds
     * @return array<string, mixed>
     */
    public function formatConversation(
        ChatConversation $conversation,
        User $viewer,
        ?int $unreadCount = null,
        ?array $onlineCompanyIds = null
    ): array {
        $conversation->loadMissing(['latestMessage.sender.profile', 'companyA', 'companyB', 'rentalJob']);

        $viewerCompanyId = (int) ($viewer->company_id ?? 0);
        if ($unreadCount === null) {
            $unreadCounts = $this->unreadCountsFor($viewer, [$conversation->id]);
            $unreadCount = (int) ($unreadCounts[$conversation->id] ?? 0);
        }

        $otherCompany = $conversation->otherCompany($viewerCompanyId);
        $otherCompanyId = $otherCompany?->id;
        $isOnline = false;
        if ($otherCompanyId) {
            $isOnline = $onlineCompanyIds === null
                ? in_array((int) $otherCompanyId, UserPresence::onlineCompanyIds([$otherCompanyId]), true)
                : isset($onlineCompanyIds[(int) $otherCompanyId]);
        }

        $lastMessage = $conversation->latestMessage;
        $viewerState = $conversation->viewerState($viewer);
        $rentalJob = $conversation->rentalJob;

        return [
            'id' => $conversation->id,
            'other_company_id' => $otherCompanyId,
            'other_company_name' => $otherCompany?->name,
            'other_company_logo' => DefaultImagePath::companyLogo($otherCompany?->logo),
            'online_status' => $isOnline,
            'other_company' => $otherCompany ? [
                'id' => $otherCompany->id,
                'name' => $otherCompany->name,
                'logo' => DefaultImagePath::companyLogo($otherCompany->logo),
                'is_online' => $isOnline,
            ] : null,
            'last_message' => $lastMessage ? [
                'id' => $lastMessage->id,
                'sender_user_id' => $lastMessage->sender_user_id,
                'sender_user_name' => $lastMessage->sender ? ChatIdentity::displayName($lastMessage->sender) : null,
                'sender_company_id' => $lastMessage->sender_company_id,
                'sender_company_name' => $this->companyName($conversation, (int) $lastMessage->sender_company_id),
                'message' => $lastMessage->trashed() ? '' : $lastMessage->message,
                'message_type' => $lastMessage->message_type,
                'created_at' => $lastMessage->created_at?->toIso8601String(),
                'is_deleted' => $lastMessage->trashed(),
            ] : null,
            'last_message_at' => $lastMessage?->created_at?->toIso8601String(),
            'unread_count' => $unreadCount,
            'archived' => $viewerState?->isArchived() ?? false,
            'archived_at' => $viewerState?->archived_at?->toIso8601String(),
            'rental_job_id' => $conversation->rental_job_id,
            'rental_job' => $rentalJob ? [
                'id' => $rentalJob->id,
                'name' => $rentalJob->name,
            ] : null,
            'created_at' => $conversation->created_at?->toIso8601String(),
        ];
    }

    /**
     * @param  array{users: Collection<int, User>, states: Collection<int, ChatConversationUserState>}|null  $receiptContext
     * @return array<string, mixed>
     */
    public function formatMessage(
        ChatMessage $message,
        User $viewer,
        ?ChatConversation $conversation = null,
        ?array $receiptContext = null
    ): array {
        $message->loadMissing('sender.profile');
        $conversation ??= $message->relationLoaded('conversation')
            ? $message->conversation
            : $message->conversation()->with(['companyA', 'companyB'])->first();

        $isDeleted = $message->trashed();
        $isMine = (int) $message->sender_user_id === (int) $viewer->id;
        $receipts = $isMine && $conversation && ! $isDeleted
            ? $this->receiptsFor($message, $conversation, $viewer, $receiptContext)
            : [
                'status' => $message->delivered_at ? ChatMessage::STATUS_DELIVERED : ChatMessage::STATUS_SENT,
                'read_by' => [],
            ];

        return [
            'id' => $message->id,
            'conversation_id' => $message->conversation_id,
            'sender_user_id' => $message->sender_user_id,
            'sender_user_name' => $message->sender ? ChatIdentity::displayName($message->sender) : null,
            'sender_company_id' => $message->sender_company_id,
            'sender_company_name' => $conversation
                ? $this->companyName($conversation, (int) $message->sender_company_id)
                : null,
            'message' => $isDeleted ? '' : $message->message,
            'message_type' => $message->message_type,
            'created_at' => $message->created_at?->toIso8601String(),
            'is_mine' => $isMine,
            'is_deleted' => $isDeleted,
            'deleted_at' => $message->deleted_at?->toIso8601String(),
            'delivered_at' => $message->delivered_at?->toIso8601String(),
            'status' => $receipts['status'],
            'read_by' => $receipts['read_by'],
        ];
    }

    private function loadConversation(ChatConversation $conversation, ?User $viewer = null): ChatConversation
    {
        $relations = ['latestMessage.sender.profile', 'companyA', 'companyB', 'rentalJob'];
        if ($viewer) {
            $conversation->load($relations);
            $conversation->load(['userStates' => fn ($states) => $states->where('user_id', $viewer->id)]);

            return $conversation;
        }

        return $conversation->load($relations);
    }

    /**
     * @param  array{archived?: bool, include_archived?: bool, general_only?: bool, rental_job_id?: int}  $filters
     */
    private function applyConversationFilters(Builder $query, User $user, array $filters): void
    {
        if (! empty($filters['rental_job_id'])) {
            $query->where('rental_job_id', (int) $filters['rental_job_id']);
        }

        if (! empty($filters['general_only'])) {
            $query->whereNull('rental_job_id');
        }

        $archivedOnly = ! empty($filters['archived']);
        $includeArchived = ! empty($filters['include_archived']);

        if ($archivedOnly) {
            $query->whereHas('userStates', function (Builder $states) use ($user) {
                $states->where('user_id', $user->id)->whereNotNull('archived_at');
            });
        } elseif (! $includeArchived) {
            $query->whereDoesntHave('userStates', function (Builder $states) use ($user) {
                $states->where('user_id', $user->id)->whereNotNull('archived_at');
            });
        }
    }

    private function companyConversationsQuery(int $companyId): Builder
    {
        return ChatConversation::query()
            ->where(function (Builder $query) use ($companyId) {
                $query->where('company_a_id', $companyId)
                    ->orWhere('company_b_id', $companyId);
            });
    }

    /**
     * @return list<int>
     */
    private function companyConversationIds(int $companyId): array
    {
        return $this->companyConversationsQuery($companyId)
            ->pluck('id')
            ->map(fn ($id) => (int) $id)
            ->all();
    }

    /**
     * Unread = non-deleted messages from the other company after this user's last_read_at.
     *
     * @param  list<int>  $conversationIds
     * @return array<int, int>
     */
    private function unreadCountsFor(User $user, array $conversationIds): array
    {
        $conversationIds = array_values(array_filter($conversationIds));
        if ($conversationIds === [] || ! $user->company_id) {
            return [];
        }

        return ChatMessage::query()
            ->from('chat_messages')
            ->leftJoin('chat_conversation_user_states as s', function ($join) use ($user) {
                $join->on('s.conversation_id', '=', 'chat_messages.conversation_id')
                    ->where('s.user_id', '=', $user->id);
            })
            ->whereIn('chat_messages.conversation_id', $conversationIds)
            ->whereNull('chat_messages.deleted_at')
            ->where('chat_messages.sender_company_id', '!=', $user->company_id)
            ->where(function ($query) {
                $query->whereNull('s.last_read_at')
                    ->orWhereColumn('chat_messages.created_at', '>', 's.last_read_at');
            })
            ->selectRaw('chat_messages.conversation_id, COUNT(*) as unread_count')
            ->groupBy('chat_messages.conversation_id')
            ->pluck('unread_count', 'conversation_id')
            ->map(fn ($count) => (int) $count)
            ->all();
    }

    /**
     * @return array{users: Collection<int, User>, states: Collection<int, ChatConversationUserState>}
     */
    private function receiptContext(ChatConversation $conversation, User $viewer): array
    {
        $viewerCompanyId = (int) ($viewer->company_id ?? 0);
        $otherCompanyId = (int) ($conversation->otherCompanyId($viewerCompanyId) ?? 0);
        if ($otherCompanyId <= 0) {
            return [
                'users' => collect(),
                'states' => collect(),
            ];
        }

        $users = User::query()
            ->where('company_id', $otherCompanyId)
            ->with('profile')
            ->get()
            ->keyBy('id');

        $states = ChatConversationUserState::query()
            ->where('conversation_id', $conversation->id)
            ->whereIn('user_id', $users->keys()->all() ?: [0])
            ->get()
            ->keyBy('user_id');

        return [
            'users' => $users,
            'states' => $states,
        ];
    }

    /**
     * Per-recipient read receipts. `status` is sent/delivered/read from the sender's view;
     * `read_by` lists which recipient-company users have actually read the message.
     *
     * @param  array{users: Collection<int, User>, states: Collection<int, ChatConversationUserState>}|null  $receiptContext
     * @return array{status: string, read_by: list<array<string, mixed>>}
     */
    private function receiptsFor(
        ChatMessage $message,
        ChatConversation $conversation,
        User $viewer,
        ?array $receiptContext
    ): array {
        $context = $receiptContext ?? $this->receiptContext($conversation, $viewer);
        $readBy = [];

        foreach ($context['users'] as $recipient) {
            $state = $context['states']->get($recipient->id);
            if ($state?->last_read_at && $message->created_at && $state->last_read_at->gte($message->created_at)) {
                $readBy[] = [
                    'user_id' => (int) $recipient->id,
                    'user_name' => ChatIdentity::displayName($recipient),
                    'read_at' => $state->last_read_at->toIso8601String(),
                ];
            }
        }

        $status = ChatMessage::STATUS_SENT;
        if ($readBy !== []) {
            $status = ChatMessage::STATUS_READ;
        } elseif ($message->delivered_at) {
            $status = ChatMessage::STATUS_DELIVERED;
        }

        return [
            'status' => $status,
            'read_by' => $readBy,
        ];
    }

    private function markIncomingMessagesDelivered(ChatConversation $conversation, User $user): void
    {
        $companyId = (int) ($user->company_id ?? 0);
        if ($companyId <= 0) {
            return;
        }

        ChatMessage::query()
            ->where('conversation_id', $conversation->id)
            ->whereNull('delivered_at')
            ->whereNull('deleted_at')
            ->where('sender_company_id', '!=', $companyId)
            ->update(['delivered_at' => now()]);
    }

    private function unarchiveForUser(ChatConversation $conversation, User $user): void
    {
        ChatConversationUserState::query()
            ->where('conversation_id', $conversation->id)
            ->where('user_id', $user->id)
            ->whereNotNull('archived_at')
            ->update(['archived_at' => null]);
    }

    private function unarchiveForCompanyUsers(ChatConversation $conversation, int $companyId): void
    {
        if ($companyId <= 0) {
            return;
        }

        $userIds = User::query()
            ->where('company_id', $companyId)
            ->pluck('id')
            ->all();

        if ($userIds === []) {
            return;
        }

        ChatConversationUserState::query()
            ->where('conversation_id', $conversation->id)
            ->whereIn('user_id', $userIds)
            ->whereNotNull('archived_at')
            ->update(['archived_at' => null]);
    }

    private function searchConversations(User $user, int $companyId, string $like, int $perPage): LengthAwarePaginator
    {
        return $this->companyConversationsQuery($companyId)
            ->where(function (Builder $query) use ($like, $companyId) {
                $query->whereHas('companyA', function (Builder $company) use ($like, $companyId) {
                    $company->where('companies.id', '!=', $companyId)
                        ->where('companies.name', 'like', $like);
                })->orWhereHas('companyB', function (Builder $company) use ($like, $companyId) {
                    $company->where('companies.id', '!=', $companyId)
                        ->where('companies.name', 'like', $like);
                })->orWhereHas('messages', function (Builder $messages) use ($like) {
                    $messages->where('message', 'like', $like)
                        ->orWhereHas('sender.profile', function (Builder $profile) use ($like) {
                            $profile->where('full_name', 'like', $like)
                                ->orWhere('first_name', 'like', $like)
                                ->orWhere('last_name', 'like', $like);
                        });
                });
            })
            ->with([
                'latestMessage.sender.profile',
                'companyA',
                'companyB',
                'rentalJob',
                'userStates' => fn ($states) => $states->where('user_id', $user->id),
            ])
            ->orderByDesc('updated_at')
            ->orderByDesc('id')
            ->paginate($perPage);
    }

    private function companyName(ChatConversation $conversation, int $companyId): ?string
    {
        $conversation->loadMissing(['companyA', 'companyB']);

        if ((int) $conversation->company_a_id === $companyId) {
            return $conversation->companyA?->name;
        }

        if ((int) $conversation->company_b_id === $companyId) {
            return $conversation->companyB?->name;
        }

        return null;
    }

    /**
     * @return list<int>
     */
    private function recipientUserIds(int $recipientCompanyId, int $senderUserId): array
    {
        if ($recipientCompanyId <= 0) {
            return [];
        }

        return User::query()
            ->where('company_id', $recipientCompanyId)
            ->where('id', '!=', $senderUserId)
            ->pluck('id')
            ->map(fn ($id) => (int) $id)
            ->values()
            ->all();
    }

    private function escapeLike(string $term): string
    {
        return str_replace(['\\', '%', '_'], ['\\\\', '\%', '\_'], $term);
    }

    /**
     * @return array<string, int>
     */
    private function meta(LengthAwarePaginator $paginator): array
    {
        return [
            'page' => $paginator->currentPage(),
            'per_page' => $paginator->perPage(),
            'total' => $paginator->total(),
            'total_pages' => $paginator->lastPage(),
        ];
    }
}
