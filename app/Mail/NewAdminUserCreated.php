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
        $greetingName = $this->user->profile->full_name ?? $this->user->username ?? 'Admin';
        $userEmail = $this->user->profile->email ?? $this->user->email ?? '';

        $heading = $this->isPasswordReset ? 'Password Reset' : 'Welcome to PSM Admin Panel';
        $bodyMessage = $this->isPasswordReset
            ? 'Your admin panel password has been reset by the Super Administrator.'
            : 'Welcome to the PSM Equipment Rental Admin Panel! Your administrator account has been created successfully.';

        $roleLine = $this->isPasswordReset ? '' : 'You have been granted <strong>' . e(ucfirst(str_replace('_', ' ', $this->user->role ?? ''))) . '</strong> access to the system.';

        $capabilitiesSection = '';
        if (!$this->isPasswordReset) {
            $capabilitiesSection = '<h3>What You Can Do:</h3><ul>';
            $capabilitiesSection .= '<li>Manage user accounts and companies</li>';
            $capabilitiesSection .= '<li>View and monitor rental jobs and supply offers</li>';
            $capabilitiesSection .= '<li>Manage products, equipment, and inventory</li>';
            $capabilitiesSection .= '<li>Access comprehensive reporting and analytics</li>';
            if ($this->user->role === 'super_admin') {
                $capabilitiesSection .= '<li><strong>Full administrative control including managing other admin users</strong></li>';
            }
            $capabilitiesSection .= '</ul>';
        }

        return new Content(
            view: 'emails.new-admin-user',
            with: [
                'user' => $this->user,
                'password' => $this->password,
                'isPasswordReset' => $this->isPasswordReset,
                'heading' => $heading,
                'greeting_name' => $greetingName,
                'body_message' => $bodyMessage,
                'role_line' => $roleLine,
                'adminPanelUrl' => rtrim(env('ADMIN_PANEL_URL', config('app.url') . '/login'), '/'),
                'user_email' => $userEmail,
                'role_display' => ucfirst(str_replace('_', ' ', $this->user->role ?? '')),
                'current_year' => (string) date('Y'),
                'capabilities_section' => $capabilitiesSection,
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

