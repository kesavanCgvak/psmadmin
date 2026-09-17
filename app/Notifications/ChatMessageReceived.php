<?php

namespace App\Notifications;

use App\Models\ChatConversation;
use App\Models\ChatMessage;
use App\Models\User;
use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Notification;
use Illuminate\Support\Str;

class ChatMessageReceived extends Notification
{
    use Queueable;

    public function __construct(
        public ChatMessage $message,
        public ChatConversation $conversation,
        public User $sender,
        public ?int $defaultContactUserId = null,
    ) {}

    /**
     * Browser/desktop notifications are delivered by the frontend Notification API
     * using the `chat.message.sent` Reverb payload. Mobile push is out of scope.
     *
     * @return list<string>
     */
    public function via(object $notifiable): array
    {
        return [];
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(object $notifiable): array
    {
        $this->conversation->loadMissing(['companyA', 'companyB']);
        $senderCompanyId = (int) $this->message->sender_company_id;
        $senderCompanyName = null;
        if ((int) $this->conversation->company_a_id === $senderCompanyId) {
            $senderCompanyName = $this->conversation->companyA?->name;
        } elseif ((int) $this->conversation->company_b_id === $senderCompanyId) {
            $senderCompanyName = $this->conversation->companyB?->name;
        }

        return [
            'conversation_id' => $this->conversation->id,
            'message_id' => $this->message->id,
            'sender_user_id' => $this->sender->id,
            'sender_company_id' => $senderCompanyId,
            'sender_name' => $this->sender->profile?->full_name ?: $this->sender->getUsername(),
            'sender_company_name' => $senderCompanyName,
            'preview' => Str::limit((string) $this->message->message, 80),
            'message_type' => $this->message->message_type,
            'default_contact_user_id' => $this->defaultContactUserId,
            'created_at' => $this->message->created_at?->toIso8601String(),
        ];
    }
}
