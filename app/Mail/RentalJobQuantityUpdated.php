<?php

namespace App\Mail;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

class RentalJobQuantityUpdated extends Mailable
{
    use Queueable, SerializesModels;

    public $rentalJob;
    public $supplyJob;
    public $receiver;
    public $updatedProducts;
    public $grandTotal;

    public function __construct($rentalJob, $supplyJob, $receiver, $updatedProducts, $grandTotal)
    {
        $this->rentalJob = $rentalJob;
        $this->supplyJob = $supplyJob;
        $this->receiver = $receiver;
        $this->updatedProducts = $updatedProducts;
        $this->grandTotal = $grandTotal;
    }

    public function build()
    {
        return $this->subject('Rental Job Updated - Product Quantities Changed')
            ->view('emails.rental_job.updated_quantities');
    }
}

