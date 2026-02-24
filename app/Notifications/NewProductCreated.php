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
        $product = $this->product->loadMissing(['brand', 'category', 'subCategory']);
        return (new MailMessage)
            ->from(config('mail.from.address'), config('mail.from.name'))
            ->subject('New Product Added to Marketplace')
            ->view('emails.product_created', [
                'user_full_name' => $fullName,
                'company_name' => $companyName,
                'user_email' => $this->user->email ?? '',
                'product_name' => $product->model ?? 'N/A',
                'product_brand' => $product->brand->name ?? 'N/A',
                'product_category' => $product->category->name ?? 'N/A',
                'product_sub_category' => $product->subCategory->name ?? 'N/A',
                'product_psm_code' => $product->psm_code ?? 'N/A',
                'webpage_url' => $product->webpage_url ?? 'N/A',
                'current_year' => (string) date('Y'),
            ]);
    }
}
