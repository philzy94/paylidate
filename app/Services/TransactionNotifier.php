<?php

namespace App\Services;

use App\Exceptions\TransactionException;
use App\Mail\CreateTransactionMail;
use App\Mail\ReportTransaction;
use App\Transaction;
use App\User;
use Illuminate\Support\Facades\Mail;

/**
 * Every email sent about an escrow transaction, so TransactionService keeps to the business rules.
 */
class TransactionNotifier
{
    protected FulfilmentService $fulfilmentService;

    public function __construct(FulfilmentService $fulfilmentService)
    {
        $this->fulfilmentService = $fulfilmentService;
    }

    /**
     * Email both parties about a new transaction and, optionally, start fulfilment.
     *
     * @throws TransactionException when the product or its owner no longer exists
     */
    public function created(Transaction $transaction, User $buyer, bool $initiateFulfilment = false): void
    {
        $product = $transaction->product ?? throw TransactionException::notFound();
        $seller = $product->user ?? throw TransactionException::notFound();
        $details = $this->details($transaction);

        if ($initiateFulfilment) {
            $this->fulfilmentService->initiate_fufilment(
                $seller->email,
                (string) $seller->name,
                $buyer->email,
                (string) $buyer->name,
                $buyer->id,
                $transaction->id,
                (string) $transaction->transaction_ref,
                $details
            );
        }

        Mail::to($buyer->email)->send(new CreateTransactionMail($buyer, $details));
        Mail::to($seller->email)->send(new CreateTransactionMail($seller, $details));
    }

    /**
     * Tell the seller, and staff, that the buyer reported a problem.
     */
    public function reported(Transaction $transaction, string $sellerEmail, string $report): void
    {
        $reference = (string) $transaction->transaction_ref;

        Mail::to($sellerEmail)->send(new ReportTransaction($this->nameOf($sellerEmail), $reference, 'report', $report));
        $this->notifyStaff(new ReportTransaction('Admin', $reference, 'report', $report));
    }

    /**
     * Tell the seller, and staff, that the buyer resolved the report.
     */
    public function resolved(Transaction $transaction, string $sellerEmail): void
    {
        $reference = (string) $transaction->transaction_ref;

        Mail::to($sellerEmail)->send(new ReportTransaction($this->nameOf($sellerEmail), $reference, 'resolve'));
        $this->notifyStaff(new ReportTransaction('Admin', $reference, 'resolve'));
    }

    /**
     * The facts about a transaction that its emails show.
     *
     * @return array<string, mixed>
     *
     * @throws TransactionException when the product no longer exists
     */
    public function details(Transaction $transaction): array
    {
        $product = $transaction->product ?? throw TransactionException::notFound();

        return [
            'id' => $transaction->id,
            'referral' => $transaction->referral,
            'transaction_ref' => $transaction->transaction_ref,
            'product_id' => $transaction->product_id,
            'product_name' => $product->name,
            'product_number' => $product->product_number,
            'type' => $product->type,
            'total_quantity' => $transaction->quantity,
            'total_price' => $product->price * $transaction->quantity,
            'description' => $transaction->description ?: 'No description',
        ];
    }

    protected function nameOf(string $email): ?string
    {
        return User::where('email', $email)->first()?->name;
    }

    protected function notifyStaff(ReportTransaction $mail): void
    {
        $recipients = config('app.admin_emails');

        if (! empty($recipients)) {
            Mail::to($recipients)->send($mail);
        }
    }
}
