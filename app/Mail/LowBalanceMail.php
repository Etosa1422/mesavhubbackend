<?php

namespace App\Mail;

use App\Models\User;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Queue\SerializesModels;

class LowBalanceMail extends Mailable
{
    use Queueable, SerializesModels;

    public $user;
    public $balance;

    public function __construct(User $user, float $balance)
    {
        $this->user    = $user;
        $this->balance = $balance;
    }

    public function build()
    {
        return $this->subject('Low Balance Alert — ' . config('app.name'))
            ->view('emails.low-balance');
    }
}
