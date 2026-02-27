<?php

namespace App\Mail;

use App\Models\User;
use App\Models\Subscription;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

class SubscriptionCanceledNotification extends Mailable
{
    use Queueable, SerializesModels;

    public $user;
    public $subscription;
    public $isImmediate;

    /**
     * Create a new message instance.
     */
    public function __construct(User $user, Subscription $subscription, bool $isImmediate = false)
    {
        $this->user = $user;
        $this->subscription = $subscription;
        $this->isImmediate = $isImmediate;
    }

    /**
     * Get the message envelope.
     */
    public function envelope(): Envelope
    {
        $subject = $this->isImmediate
            ? 'Your Subscription Has Been Canceled - Pro Subrental Marketplace'
            : 'Subscription Cancellation Confirmed - Pro Subrental Marketplace';

        return new Envelope(
            subject: $subject,
        );
    }

    /**
     * Get the message content definition.
     */
    public function content(): Content
    {
        $username = $this->user->profile->full_name ?? $this->user->username ?? 'Valued Customer';
        $email = $this->user->profile->email ?? $this->user->email;
        
        return new Content(
            view: 'emails.subscriptionCanceled',
            with: [
                'username' => $username,
                'email' => $email,
                'plan_name' => $this->subscription->plan_name,
                'status' => $this->subscription->stripe_status,
                'amount' => $this->subscription->amount,
                'currency' => $this->subscription->currency ?? 'USD',
                'interval' => $this->subscription->interval ?? 'month',
                'current_period_end' => $this->subscription->current_period_end?->format('F j, Y'),
                'is_immediate' => $this->isImmediate,
                'app_url' => env('APP_FRONTEND_URL', config('app.url')),
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

