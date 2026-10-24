<?php

namespace App\Mail;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Mail\Mailable;
use Illuminate\Queue\SerializesModels;

class DisputeMail extends Mailable
{
    use Queueable;
    use SerializesModels;

    public string $user;
    /** @var array<string, mixed> */
    public array $transaction;
    public string $dispute;

    /**
     * Create a new message instance.
     *
     * @param  array<string, mixed>  $transaction
     */
    public function __construct(string $subject, string $dispute, array $transaction, string $user)
    {

        $this->subject = $subject;
        $this->user = $user;
        $this->transaction = $transaction;
        $this->dispute = $dispute;
    }
    /**
     * Build the message.
     *
     * @return $this
     */
    public function build(): static
    {

        return $this->from('hello@paylidate.com', 'Paylidate')
        ->subject('Dispute (' . $this->subject . ')')->view('mails.dispute');
    }
}
