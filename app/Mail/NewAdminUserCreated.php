<?php

namespace App\Mail;

use App\Models\User;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

class NewAdminUserCreated extends Mailable
{
    use Queueable, SerializesModels;

    public $user;
    public $password;
    public $isPasswordReset;

    /**
     * Create a new message instance.
     */
    public function __construct(User $user, string $password, bool $isPasswordReset = false)
    {
        $this->user = $user;
        $this->password = $password;
        $this->isPasswordReset = $isPasswordReset;
    }

    /**
     * Get the message envelope.
     */
    public function envelope(): Envelope
    {
        $subject = $this->isPasswordReset
            ? 'Your Admin Panel Password Has Been Reset'
            : 'Welcome to PSM Admin Panel';

        return new Envelope(
            subject: $subject,
        );
    }

    /**
     * Get the message content definition.
     */
    public function content(): Content
    {
        return new Content(
            view: 'emails.new-admin-user',
            with: [
                'user' => $this->user,
                'password' => $this->password,
                'isPasswordReset' => $this->isPasswordReset,
                'adminPanelUrl' => config('app.url') . '/login',
            ],
        );
    }

    /**
     * Get the attachments for the message.
     *
     * @return array<int, \Illuminate\Mail\Mailables\Attachment>
     */
    public function attachments(): array
    {
        return [];
    }
}

