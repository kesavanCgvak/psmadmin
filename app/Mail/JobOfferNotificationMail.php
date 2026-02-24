<?php

namespace App\Mail;

use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Queue\SerializesModels;

class JobOfferNotificationMail extends Mailable
{
    use Queueable, SerializesModels;

    public $sender_company_name;
    public $receiver_contact_name;
    public $version;
    public $total_price;
    public $currency;
    public $status;
    public $products;

    /**
     * Create a new message instance.
     */
    public function __construct(array $mailContent)
    {
        $this->sender_company_name   = $mailContent['sender_company_name'];
        $this->receiver_contact_name = $mailContent['receiver_contact_name'];
        $this->version               = $mailContent['version'];
        $this->total_price           = $mailContent['total_price'];
        $this->currency              = $mailContent['currency'];
        $this->status                = $mailContent['status'];
        $this->products              = $mailContent['products'];
    }

    /**
     * Build the message.
     */
    public function build()
    {
        return $this->subject('New Job Offer Received - Pro Subrental Marketplace')
            ->from('no-reply@prosubmarket.com', 'Pro Subrental Marketplace')
            ->view('emails.jobOfferNotification')
            ->with([
                'sender_company_name'   => $this->sender_company_name,
                'receiver_contact_name' => $this->receiver_contact_name,
                'version'               => $this->version,
                'total_price'           => $this->total_price,
                'currency'              => $this->currency,
                'status'                => $this->status,
                'products'              => $this->products,
                'current_year'          => (string) date('Y'),
            ]);
    }
}
