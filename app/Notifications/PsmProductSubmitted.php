<?php

namespace App\Notifications;

use App\Models\EmailLog;
use App\Models\PsmProductSubmission;
use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class PsmProductSubmitted extends Notification
{
    use Queueable;

    public function __construct(public PsmProductSubmission $submission) {}

    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $submission = $this->submission;
        $submitter = $submission->submitter;
        $data = [
            'product_name' => $submission->name,
            'description' => $submission->description ?? 'N/A',
            'submitter_name' => $submitter?->profile?->full_name ?: ($submitter?->username ?? 'N/A'),
            'submitter_email' => $submitter?->email ?? 'N/A',
            'company_name' => $submission->company?->name ?? 'N/A',
            'submitted_at' => $submission->created_at?->format('M d, Y H:i') ?? now()->format('M d, Y H:i'),
            'review_url' => $this->publicReviewUrl($submission),
            'logo_url' => $this->publicLogoUrl(),
            'current_year' => (string) date('Y'),
        ];

        return (new MailMessage)
            ->from(config('mail.from.address'), config('mail.from.name'))
            ->subject('New PSM Product Submitted for Review')
            ->withSymfonyMessage(function ($message): void {
                $message->getHeaders()->addTextHeader(
                    EmailLog::EMAIL_TYPE_HEADER,
                    EmailLog::TYPE_PSM_PRODUCT_SUBMISSION
                );
                $message->getHeaders()->addTextHeader(
                    EmailLog::MAIL_CLASS_HEADER,
                    self::class
                );
            })
            ->view([
                'html' => 'emails.psm_product_submitted',
                'text' => 'emails.psm_product_submitted_plain',
            ], $data);
    }

    private function publicReviewUrl(PsmProductSubmission $submission): ?string
    {
        $origin = $this->publicWebOrigin();
        if ($origin === null) {
            return null;
        }

        return $origin.'/admin/psm-product-submissions/'.$submission->id;
    }

    private function publicLogoUrl(): ?string
    {
        $configured = trim((string) config('mail.logo_url'));
        if ($configured !== '' && $this->isPublicHttpUrl($configured)) {
            return $configured;
        }

        $origin = $this->publicWebOrigin();

        return $origin !== null ? $origin.'/images/logo-white.png' : null;
    }

    private function publicWebOrigin(): ?string
    {
        foreach ([config('mail.admin.panel_url'), config('app.url')] as $url) {
            $url = trim((string) $url);
            if ($url === '' || ! $this->isPublicHttpUrl($url)) {
                continue;
            }

            $parts = parse_url($url);

            return ($parts['scheme'] ?? 'https').'://'.($parts['host'] ?? '');
        }

        return null;
    }

    private function isPublicHttpUrl(string $url): bool
    {
        $parts = parse_url($url);
        $scheme = strtolower((string) ($parts['scheme'] ?? ''));
        $host = strtolower((string) ($parts['host'] ?? ''));

        if (! in_array($scheme, ['http', 'https'], true) || $host === '') {
            return false;
        }

        if (in_array($host, ['localhost', '127.0.0.1', '::1'], true)) {
            return false;
        }

        return ! str_ends_with($host, '.test')
            && ! str_ends_with($host, '.local')
            && ! str_ends_with($host, '.invalid');
    }
}
