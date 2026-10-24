<?php

namespace App\Mail;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Mail\Mailable;
use Illuminate\Queue\SerializesModels;

class FulfilmentMail extends Mailable
{
    use Queueable;
    use SerializesModels;

    public string $user;
    public string $data;
    /** @var array<string, mixed> */
    public array $transaction;

    /**
     * Create a new message instance.
     *
     * @param  array<string, mixed>  $transaction
     */
    public function __construct(string $user, string $data, array $transaction)
    {
        $this->user = $user;
        $this->data = $data;
        $this->transaction = $transaction;
    }

    /**
     * Build the message.
     *
     * @return $this
     */
    public function build(): static
    {
        return $this->from('hello@paylidate.com', 'Paylidate')->view('mails.fulfilment');
    }
}
