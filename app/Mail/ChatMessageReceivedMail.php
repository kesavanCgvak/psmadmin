<?php

namespace App\Mail;

use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
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
    ) {}

    public function envelope(): Envelope
    {
        return new Envelope(
            subject: sprintf('New message from %s at %s', $this->senderName, $this->senderCompanyName),
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
