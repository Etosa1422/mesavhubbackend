<?php

namespace App\Mail;

use App\Models\User;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Queue\SerializesModels;

class SecurityAlertMail extends Mailable
{
    use Queueable, SerializesModels;

    public $user;
    public $alertType;
    public $alertTitle;
    public $alertMessage;

    /**
     * @param User   $user
     * @param string $alertType    e.g. 'password_changed', 'email_changed', '2fa_enabled', 'api_key_regenerated'
     * @param string $alertTitle   Short title shown in the email header
     * @param string $alertMessage Body message explaining what happened
     */
    public function __construct(User $user, string $alertType, string $alertTitle, string $alertMessage)
    {
        $this->user         = $user;
        $this->alertType    = $alertType;
        $this->alertTitle   = $alertTitle;
        $this->alertMessage = $alertMessage;
    }

    public function build()
    {
        return $this->subject("Security Alert: {$this->alertTitle} — " . config('app.name'))
            ->view('emails.security-alert');
    }
}
