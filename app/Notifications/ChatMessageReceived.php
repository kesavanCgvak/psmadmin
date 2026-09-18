<?php

namespace App\Notifications;

use App\Mail\ChatMessageReceivedMail;
use App\Models\ChatConversation;
use App\Models\ChatMessage;
use App\Models\User;
use App\Support\ChatIdentity;
use App\Support\ChatUrls;
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
     * using the `chat.message.sent` Reverb payload. Offline email is sent by
     * SendChatEmailNotificationJob; this class remains the payload/mail shape.
     *
     * @return list<string>
     */
    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): ChatMessageReceivedMail
    {
        $this->conversation->loadMissing(['companyA', 'companyB']);
        $this->sender->loadMissing('profile');

        $recipient = $notifiable instanceof User ? $notifiable : null;

        return new ChatMessageReceivedMail(
            recipientName: $recipient ? ChatIdentity::displayName($recipient) : '',
            senderName: ChatIdentity::displayName($this->sender),
            senderCompanyName: $this->senderCompanyName() ?: 'a company',
            preview: Str::limit(trim((string) $this->message->message), 80),
            conversationUrl: ChatUrls::conversation((int) $this->conversation->id),
            conversationId: (int) $this->conversation->id,
        );
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(object $notifiable): array
    {
        $this->conversation->loadMissing(['companyA', 'companyB']);

        return [
            'conversation_id' => $this->conversation->id,
            'message_id' => $this->message->id,
            'sender_user_id' => $this->sender->id,
            'sender_company_id' => (int) $this->message->sender_company_id,
            'sender_name' => ChatIdentity::displayName($this->sender),
            'sender_company_name' => $this->senderCompanyName(),
            'preview' => Str::limit((string) $this->message->message, 80),
            'message_type' => $this->message->message_type,
            'default_contact_user_id' => $this->defaultContactUserId,
            'created_at' => $this->message->created_at?->toIso8601String(),
        ];
    }

    private function senderCompanyName(): ?string
    {
        $senderCompanyId = (int) $this->message->sender_company_id;

        if ((int) $this->conversation->company_a_id === $senderCompanyId) {
            return $this->conversation->companyA?->name;
        }

        if ((int) $this->conversation->company_b_id === $senderCompanyId) {
            return $this->conversation->companyB?->name;
        }

        return null;
    }
}
