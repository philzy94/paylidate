<?php

namespace App\Services;

use App\Dispute;
use App\DisputeChat;
use App\Exceptions\DisputeException;
use App\Exceptions\TransactionException;
use App\Mail\DisputeMail;
use App\Transaction;
use App\User;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;

class DisputeService
{
    protected TransactionService $transactions;

    public function __construct(TransactionService $transactions)
    {
        $this->transactions = $transactions;
    }

    /**
     * Disputes on a transaction, visible to its two parties and to staff.
     */
    /** @return Collection<int, Dispute> */
    public function forTransaction(User $user, int $transactionId): Collection
    {
        $transaction = $this->transaction($transactionId);
        $this->authorize($user, $transaction);

        return Dispute::where('transaction_id', $transactionId)
            ->with('user', 'transaction', 'dispute_chat')
            ->orderBy('dispute_solved')
            ->get();
    }

    /**
     * Open a dispute and tell both parties and staff.
     */
    public function open(User $user, int $transactionId, string $subject, string $description): Dispute
    {
        $transaction = $this->transaction($transactionId);
        $this->authorize($user, $transaction, false);

        $dispute = DB::transaction(function () use ($user, $transaction, $subject, $description) {
            $dispute = Dispute::create([
                'user_id' => $user->id,
                'transaction_id' => $transaction->id,
                'subject' => $subject,
                'dispute' => $description,
            ]);

            $transaction->update(['dispute' => true]);

            return $dispute;
        });

        $details = $this->transactions->emailDetails($transaction->load('payment'));

        foreach ([$transaction->secondary_user, $transaction->product?->user] as $party) {
            if ($party) {
                Mail::to($party->email)->send(new DisputeMail($subject, $description, $details, $party->name ?? ''));
            }
        }

        $recipients = config('app.admin_emails');

        if (! empty($recipients)) {
            Mail::to($recipients)->send(new DisputeMail($subject, $description, $details, 'ADMIN'));
        }

        return $dispute;
    }

    /**
     * Close a dispute. Only the person who opened it, or staff, may do so.
     */
    public function resolve(User $user, int $disputeId): Dispute
    {
        $dispute = Dispute::find($disputeId);

        if (! $dispute) {
            throw DisputeException::notFound();
        }

        if ($dispute->user_id !== $user->id && ! $user->is_admin) {
            throw DisputeException::forbidden();
        }

        DB::transaction(function () use ($dispute) {
            $dispute->update(['dispute_solved' => true]);

            $stillOpen = Dispute::where('transaction_id', $dispute->transaction_id)->where('dispute_solved', false)->exists();

            if (! $stillOpen) {
                Transaction::where('id', $dispute->transaction_id)->update([
                    'dispute' => false,
                    'status' => TransactionService::STATUS_PENDING,
                ]);
            }
        });

        return $dispute->load('user', 'transaction');
    }

    /** @return Collection<int, DisputeChat> */
    public function messages(User $user, int $disputeId): Collection
    {
        $dispute = $this->dispute($disputeId);
        $this->authorize($user, $this->transactionOf($dispute));

        return DisputeChat::where('dispute_id', $disputeId)->with('user:id,name')->orderBy('created_at')->get();
    }

    public function addMessage(User $user, int $disputeId, string $message): DisputeChat
    {
        $dispute = $this->dispute($disputeId);
        $this->authorize($user, $this->transactionOf($dispute));

        return DisputeChat::create([
            'user_id' => $user->id,
            'transaction_id' => $dispute->transaction_id,
            'dispute_id' => $dispute->id,
            'message' => $message,
            'sender' => $user->name,
        ]);
    }

    protected function dispute(int $id): Dispute
    {
        $dispute = Dispute::with('transaction.product')->find($id);

        if (! $dispute) {
            throw DisputeException::notFound();
        }

        return $dispute;
    }

    protected function transactionOf(Dispute $dispute): Transaction
    {
        return $dispute->transaction ?? throw TransactionException::notFound();
    }

    protected function transaction(int $id): Transaction
    {
        $transaction = Transaction::with('product.user', 'secondary_user')->find($id);

        if (! $transaction) {
            throw TransactionException::notFound();
        }

        return $transaction;
    }

    protected function authorize(User $user, Transaction $transaction, bool $allowStaff = true): void
    {
        $isParty = $transaction->user_id === $user->id || $transaction->product?->user_id === $user->id;

        if (! $isParty && ! ($allowStaff && $user->is_admin)) {
            throw DisputeException::forbidden();
        }
    }
}
