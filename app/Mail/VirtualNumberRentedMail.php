<?php

namespace App\Mail;

use App\Models\User;
use App\Models\VirtualNumberRental;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Queue\SerializesModels;

class VirtualNumberRentedMail extends Mailable
{
    use Queueable, SerializesModels;

    public $rental;
    public $user;

    public function __construct(VirtualNumberRental $rental, User $user)
    {
        $this->rental = $rental;
        $this->user   = $user;
    }

    public function build()
    {
        return $this->subject('Virtual Number Rented — ' . config('app.name'))
            ->view('emails.virtual-number-rented');
    }
}
