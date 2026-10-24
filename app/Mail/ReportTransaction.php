<?php

namespace App\Mail;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Mail\Mailable;
use Illuminate\Queue\SerializesModels;

class ReportTransaction extends Mailable
{
    use Queueable;
    use SerializesModels;

    /**
     * Create a new message instance.
     *
     * @return void
     */
    public ?string $user_name;
    public string $type;
    public ?string $report;
    public string $transaction_ref;

    public function __construct(?string $user_name, string $transaction_ref, string $type, ?string $report = null)
    {

        $this->user_name = $user_name;
        $this->type = $type;
        $this->report = $report;
        $this->transaction_ref = $transaction_ref;
    }

    /**
     * Build the message.
     *
     * @return $this
     */
    public function build(): static
    {
        return $this->from('hello@paylidate.com', 'Paylidate')
        ->subject('Report Transaction')->view('mails.report_transaction');
    }
}
