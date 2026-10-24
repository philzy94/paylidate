<?php

namespace App\Mail;

use App\User;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Mail\Mailable;
use Illuminate\Queue\SerializesModels;

class CreateTransactionMail extends Mailable
{
    use Queueable;
    use SerializesModels;

    public User $user;
    /** @var array<string, mixed> */
    public array $transaction;

    /**
     * Create a new message instance.
     *
     * @param  array<string, mixed>  $transaction
     */
    public function __construct(User $user, array $transaction)
    {

        $this->user = $user;
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
        ->subject('New transaction')->view('mails.create_transaction');
    }
}
