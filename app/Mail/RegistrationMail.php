<?php

namespace App\Mail;

use App\User;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Mail\Mailable;
use Illuminate\Queue\SerializesModels;

class RegistrationMail extends Mailable implements ShouldQueue
{
    use Queueable;
    use SerializesModels;

    public User $user;
    public string $verifyEmailLink;
    /**
     * Create a new message instance.
     *
     * @return void
     */
    public function __construct(User $user, string $verifyEmailLink)
    {
        $this->user = $user;
        $this->verifyEmailLink = $verifyEmailLink;
    }

    /**
     * Build the message.
     *
     * @return $this
     */
    public function build(): static
    {
        return $this->from('paylidatesupport@lotusfort.com', 'Paylidate')
        ->subject('Verify Email')->view('mails.registration');
    }
}
