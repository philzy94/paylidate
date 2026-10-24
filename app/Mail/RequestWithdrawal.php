<?php

namespace App\Mail;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Mail\Mailable;
use Illuminate\Queue\SerializesModels;

class RequestWithdrawal extends Mailable
{
    use Queueable;
    use SerializesModels;

    /**
     * Create a new message instance.
     *
     * @return void
     */
    public string $user_name;
    public int|string $transaction_id;

    public function __construct(string $user_name, int|string $transaction_id)
    {

        $this->user_name = $user_name;
        $this->transaction_id = $transaction_id;
    }

    /**
     * Build the message.
     *
     * @return $this
     */
    public function build(): static
    {
        return $this->from('hello@paylidate.com', 'Paylidate')
        ->subject('Withdrawal Request')->view('mails.request_withdrawal');
    }
}
