<?php

namespace App\Mail;

use App\Models\EmailLog;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Mail\Mailables\Headers;
use Illuminate\Queue\SerializesModels;

class ChatMessageReceivedMail extends Mailable
{
    use Queueable, SerializesModels;

    public function __construct(
        public string $recipientName,
        public string $senderName,
        public string $senderCompanyName,
        public string $preview,
        public string $conversationUrl,
        public int $conversationId,
        public int $messageId = 0,
    ) {}

    public function envelope(): Envelope
    {
        return new Envelope(
            subject: sprintf('New message from %s at %s', $this->senderName, $this->senderCompanyName),
        );
    }

    public function headers(): Headers
    {
        $referenceId = $this->messageId > 0 ? $this->messageId : $this->conversationId;

        return new Headers(
            text: [
                EmailLog::EMAIL_TYPE_HEADER => EmailLog::TYPE_CHAT_NOTIFICATION,
                EmailLog::MAIL_CLASS_HEADER => self::class,
                EmailLog::REFERENCE_ID_HEADER => (string) $referenceId,
            ],
        );
    }

    public function content(): Content
    {
        return new Content(
            view: 'emails.chat_message_received',
            with: [
                'recipient_name' => $this->recipientName,
                'sender_name' => $this->senderName,
                'sender_company_name' => $this->senderCompanyName,
                'preview' => $this->preview,
                'conversation_url' => $this->conversationUrl,
                'current_year' => (string) date('Y'),
            ],
        );
    }
}
