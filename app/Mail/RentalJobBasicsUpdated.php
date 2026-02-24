<?php

namespace App\Mail;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

class RentalJobBasicsUpdated extends Mailable
{
    use Queueable, SerializesModels;

    public $rentalJob;
    public $supplyJob;
    public $receiver;

    public function __construct($rentalJob, $supplyJob, $receiver)
    {
        $this->rentalJob = $rentalJob;
        $this->supplyJob = $supplyJob;
        $this->receiver = $receiver;
    }

    public function build()
    {
        return $this->subject('Rental Job Updated - Basic Details Changed')
            ->view('emails.rental_job.updated_basics')
            ->with('current_year', (string) date('Y'));
    }
}

