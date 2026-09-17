<?php

namespace App\Services;

use App\Events\ChatMessageSent;
use App\Models\ChatConversation;
use App\Models\ChatConversationUserState;
use App\Models\ChatMessage;
use App\Models\Company;
use App\Models\User;
use App\Support\DefaultImagePath;
use App\Support\UserPresence;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class ChatService
{
    /**
     * Find or create the general conversation between the user's company and another company.
     *
     * @return array{conversation: ChatConversation, created: bool}
     */
    public function findOrCreateCompanyConversation(User $user, int $otherCompanyId): array
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

        [$companyAId, $companyBId] = ChatConversation::orderedCompanyIds($userCompanyId, $otherCompanyId);
        $pairKey = ChatConversation::pairKeyFor($userCompanyId, $otherCompanyId);

        try {
            return DB::transaction(function () use ($user, $companyAId, $companyBId, $pairKey) {
                $existing = ChatConversation::query()
                    ->where('pair_key', $pairKey)
                    ->whereNull('rental_job_id')
                    ->lockForUpdate()
                    ->first();

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
                    'rental_job_id' => null,
                    'pair_key' => $pairKey,
                ]);

                return [
                    'conversation' => $this->loadConversation($conversation),
                    'created' => true,
                ];
            });
        } catch (QueryException $e) {
            $existing = ChatConversation::query()
                ->where('pair_key', $pairKey)
                ->whereNull('rental_job_id')
                ->first();

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
     * @return array{data: list<array<string, mixed>>, meta: array<string, int>}
     */
    public function listConversations(User $user, int $perPage): array
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
                ],
            ];
        }

        $paginator = ChatConversation::query()
            ->where(function ($query) use ($companyId) {
                $query->where('company_a_id', $companyId)
                    ->orWhere('company_b_id', $companyId);
            })
            ->with(['latestMessage.sender.profile', 'companyA', 'companyB'])
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

        return [
            'data' => $data,
            'meta' => $this->meta($paginator),
        ];
    }

    /**
     * @return array{data: list<array<string, mixed>>, meta: array<string, int>}
     */
    public function listMessages(ChatConversation $conversation, User $user, int $perPage): array
    {
        $paginator = $conversation->messages()
            ->with('sender.profile')
            ->orderByDesc('created_at')
            ->orderByDesc('id')
            ->paginate($perPage);

        $data = $paginator->getCollection()
            ->map(fn (ChatMessage $message) => $this->formatMessage($message, $user))
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

        $chatMessage = DB::transaction(function () use ($conversation, $sender, $senderCompanyId, $message, $messageType) {
            $chatMessage = ChatMessage::create([
                'conversation_id' => $conversation->id,
                'sender_user_id' => $sender->id,
                'sender_company_id' => $senderCompanyId,
                'message' => $message,
                'message_type' => $messageType,
            ]);

            $conversation->touch();

            return $chatMessage;
        });

        $chatMessage->load('sender.profile');

        $recipientCompanyId = (int) $conversation->otherCompanyId($senderCompanyId);
        $recipientCompany = $conversation->otherCompany($senderCompanyId);
        $recipientUserIds = $this->recipientUserIds($recipientCompanyId, (int) $sender->id);
        $defaultContactUserId = $recipientCompany?->default_contact_id
            ? (int) $recipientCompany->default_contact_id
            : null;

        event(new ChatMessageSent(
            message: $chatMessage,
            conversation: $conversation,
            sender: $sender,
            senderCompanyId: $senderCompanyId,
            recipientCompanyId: $recipientCompanyId,
            recipientUserIds: $recipientUserIds,
            defaultContactUserId: $defaultContactUserId,
        ));

        return $chatMessage;
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

        $unreadCounts = $this->unreadCountsFor($user, [$conversation->id]);

        return [
            'conversation_id' => $conversation->id,
            'last_read_at' => $state->last_read_at?->toIso8601String(),
            'unread_count' => (int) ($unreadCounts[$conversation->id] ?? 0),
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
        $conversation->loadMissing(['latestMessage.sender.profile', 'companyA', 'companyB']);

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
                'sender_user_name' => $lastMessage->sender ? $this->displayName($lastMessage->sender) : null,
                'sender_company_id' => $lastMessage->sender_company_id,
                'message' => $lastMessage->message,
                'message_type' => $lastMessage->message_type,
                'created_at' => $lastMessage->created_at?->toIso8601String(),
            ] : null,
            'last_message_at' => $lastMessage?->created_at?->toIso8601String(),
            'unread_count' => $unreadCount,
            'created_at' => $conversation->created_at?->toIso8601String(),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function formatMessage(ChatMessage $message, User $viewer): array
    {
        $message->loadMissing('sender.profile');

        return [
            'id' => $message->id,
            'conversation_id' => $message->conversation_id,
            'sender_user_id' => $message->sender_user_id,
            'sender_user_name' => $message->sender ? $this->displayName($message->sender) : null,
            'sender_company_id' => $message->sender_company_id,
            'message' => $message->message,
            'message_type' => $message->message_type,
            'created_at' => $message->created_at?->toIso8601String(),
            'is_mine' => (int) $message->sender_user_id === (int) $viewer->id,
        ];
    }

    private function loadConversation(ChatConversation $conversation): ChatConversation
    {
        return $conversation->load(['latestMessage.sender.profile', 'companyA', 'companyB']);
    }

    /**
     * Unread = messages from the other company after this user's last_read_at.
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

    private function displayName(User $user): string
    {
        $name = trim((string) ($user->profile?->full_name ?: ''));

        return $name !== '' ? $name : $user->getUsername();
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
