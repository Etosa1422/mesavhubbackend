<?php

namespace App\Mail;

use App\Models\AffiliatePayout;
use App\Models\User;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Queue\SerializesModels;

class AffiliatePayoutRequestedMail extends Mailable
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
        return $this->subject('Affiliate Payout Request Received — ' . config('app.name'))
            ->view('emails.affiliate-payout-requested');
    }
}
