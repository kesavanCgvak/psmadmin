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
        $amount = $this->subscription->amount;
        $currency = strtoupper($this->subscription->currency ?? 'USD');
        $interval = $this->subscription->interval ?? 'month';
        $currentPeriodEnd = $this->subscription->current_period_end?->format('F j, Y');

        $heading = $this->isImmediate
            ? 'Your subscription has been canceled'
            : 'Subscription cancellation confirmed';

        $cancellationMessage = $this->isImmediate
            ? "We're sorry to see you go. Your subscription has been canceled and your access will end immediately."
            : "We've received your request to cancel your subscription. Your subscription will remain active until the end of your current billing period.";

        $serviceContinuesUntil = (!$this->isImmediate && $currentPeriodEnd) ? $currentPeriodEnd : '—';

        $importantNotice = '';
        if (!$this->isImmediate && $currentPeriodEnd) {
            $importantNotice = '<div style="background-color: #e3f2fd; border-left: 4px solid #2196f3; padding: 15px; margin: 20px 0; border-radius: 4px;">'
                . '<p style="margin: 0;"><strong>Important:</strong> Your subscription will remain active until <strong>' . e($currentPeriodEnd) . '</strong>. You\'ll continue to have full access to all features until then.</p>'
                . '</div>';
        }

        return new Content(
            view: 'emails.subscriptionCanceled',
            with: [
                'username' => $username,
                'email' => $email,
                'heading' => $heading,
                'cancellation_message' => $cancellationMessage,
                'plan_name' => $this->subscription->plan_name,
                'status' => ucfirst($this->subscription->stripe_status),
                'billing_line' => $amount ? ($currency . ' ' . number_format((float) $amount, 2) . ' / ' . $interval) : '—',
                'service_continues_until' => $serviceContinuesUntil,
                'important_notice' => $importantNotice,
                'app_url' => rtrim(env('APP_FRONTEND_URL', config('app.url')), '/'),
                'current_year' => (string) date('Y'),
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

