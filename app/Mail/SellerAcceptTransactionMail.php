<?php

namespace App\Mail;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Mail\Mailable;
use Illuminate\Queue\SerializesModels;

class SellerAcceptTransactionMail extends Mailable
{
    use Queueable;
    use SerializesModels;

    public string $secondary_email;
    public string $email;
    /** @var array<string, mixed> */
    public array $transaction;

    /**
     * Create a new message instance.
     *
     * @param  array<string, mixed>  $transaction
     */
    public function __construct(string $secondary_email, string $email, array $transaction)
    {

        $this->secondary_email = $secondary_email;
        $this->email = $email;
        $this->transaction = $transaction;
    }

    /**
     * Build the message.
     *
     * @return $this
     */
    public function build(): static
    {
        return $this->from('hello@paylidate.com', 'Paylidate')
        ->subject('Review and accept transaction terms')->view('mails.seller_accept_transaction');
    }
}
