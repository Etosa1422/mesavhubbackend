<?php

namespace App\Mail;

use App\Models\Order;
use App\Models\User;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Queue\SerializesModels;

class OrderStatusMail extends Mailable
{
    use Queueable, SerializesModels;

    public $order;
    public $user;

    public function __construct(Order $order, User $user)
    {
        $this->order = $order;
        $this->user  = $user;
    }

    public function build()
    {
        $status = ucfirst($this->order->status);
        return $this->subject("Order #{$this->order->id} — {$status}")
            ->view('emails.order-status');
    }
}
