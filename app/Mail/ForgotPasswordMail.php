<?php

namespace App\Mail;

use App\User;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Mail\Mailable;
use Illuminate\Queue\SerializesModels;

class ForgotPasswordMail extends Mailable
{
    use Queueable;
     use SerializesModels;

    public User $user;
    public string $passwordResetLink;
    /**
     * Create a new message instance.
     *
     * @return void
     */
    public function __construct(User $user, string $passwordResetLink)
    {
        $this->user = $user;
        $this->passwordResetLink = $passwordResetLink;
    }

    /**
     * Build the message.
     *
     * @return $this
     */
    public function build(): static
    {
        return $this->from('no_reply@paylidate.com', 'Paylidate')
        ->subject('Reset Pasword')->view('mails.forgot_password');
    }
}
