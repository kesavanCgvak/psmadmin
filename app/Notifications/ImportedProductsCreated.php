<?php

namespace App\Notifications;

use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Notification;
use Illuminate\Notifications\Messages\MailMessage;
use Log;

class ImportedProductsCreated extends Notification
{
    use Queueable;

    protected array $products;
    protected string $userFullName;
    protected string $userEmail;
    protected string $companyName;

    public function __construct(
        array $products,
        string $userFullName,
        string $userEmail,
        string $companyName
    ) {
        $this->products = $products;
        $this->userFullName = $userFullName;
        $this->userEmail = $userEmail;
        $this->companyName = $companyName;
    }

    public function via($notifiable)
    {
        return ['mail'];
    }

    public function toMail($notifiable)
    {
        Log::info('Preparing ImportedProductsCreated software code for ' . $this->products[0]->equipments[0]->software_code);
        return (new MailMessage)
            ->subject('Product Import Completed - New Items Added')
            ->view('emails.imported_products', [
                'products'      => $this->products,
                'user_full_name'=> $this->userFullName,
                'user_email'    => $this->userEmail,
                'company_name'  => $this->companyName,
            ]);
    }
}
