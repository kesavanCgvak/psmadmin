<?php

namespace App\Notifications;

use App\Models\Product;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;
use Log;

class NewProductCreated extends Notification
{
    use Queueable;

    public Product $product;
    public $user;

    public function __construct(Product $product, $user)
    {
        $this->product = $product;
        $this->user = $user;
    }

    public function via($notifiable)
    {
        return ['mail'];
    }

    public function toMail($notifiable)
    {
        $fullName = $this->user->profile->full_name ?? 'N/A';
        $companyName = $this->user->company->name ?? 'N/A';
        return (new MailMessage)
            ->from(config('mail.from.address'), config('mail.from.name'))
            ->subject('New Product Added to Marketplace')
            ->view('emails.product_created', [
                'product' => $this->product,
                'user' => $this->user,
                'user_full_name' => $fullName,
                'company_name' => $companyName,
                'user_email' => $this->user->email,
            ]);
    }
}
