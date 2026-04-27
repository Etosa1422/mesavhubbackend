<?php

namespace App\Mail;

use App\Models\AffiliatePayout;
use App\Models\User;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Queue\SerializesModels;

class AffiliatePayoutStatusMail extends Mailable
{
    use Queueable, SerializesModels;

    public $payout;
    public $user;

    public function __construct(AffiliatePayout $payout, User $user)
    {
        $this->payout = $payout;
        $this->user   = $user;
    }

    public function build()
    {
        $status = ucfirst($this->payout->status);
        return $this->subject("Affiliate Payout {$status} — " . config('app.name'))
            ->view('emails.affiliate-payout-status');
    }
}
