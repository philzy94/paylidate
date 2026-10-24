<?php

namespace App\Mail;

use App\Wallet;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Mail\Mailable;
use Illuminate\Queue\SerializesModels;

class WalletCreated extends Mailable
{
    use Queueable;
    use SerializesModels;

    public string $user;

    /**
     * Create a new message instance.
     *
     * @return void
     */
    public function __construct(string $user)
    {
        //
        $this->user = $user;
    }

    /**
     * Build the message.
     *
     * @return $this
     */
    public function build(): static
    {
        return $this->from('hello@paylidate.com', 'Paylidate')->view('mails.wallet_created');
    }
}
