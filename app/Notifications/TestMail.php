<?php

namespace App\Notifications;

use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class TestMail extends Notification
{
    use Queueable;

    /**
     * Notification delivery channels
     */
    public function via($notifiable)
    {
        return ['mail'];
    }

    /**
     * Build the email message
     */
    public function toMail($notifiable)
    {
        return (new MailMessage)
            ->from(config('mail.from.address'), config('mail.from.name'))
            ->subject('Test Email from Laravel')
            ->line('This is a test email to verify that your mail configuration is working.')
            ->line('If you receive this email, your SMTP settings are correct.');
    }
}
