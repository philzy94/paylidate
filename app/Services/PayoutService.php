<?php

namespace App\Services;

use App\Bank;
use App\Exceptions\PayoutException;
use App\Mail\RequestWithdrawal;
use App\Payment;
use App\Referer;
use App\ReferralWidrawal;
use App\Refund;
use App\Transaction;
use App\User;
use App\Withdrawal;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;

/**
 * Money leaving the escrow: seller withdrawals, buyer refunds and referral bonuses.
 *
 * Requests are created by the owner of the money and validated against the
 * database; the actual bank transfer is staff-only and is built from the stored
 * request (amount and bank account), never from request input.
 */
class PayoutService
{
    public const MIN_REFERRAL_PAYOUT = 3000;

    protected FlutterwaveService $flutterwave;

    public function __construct(FlutterwaveService $flutterwave)
    {
        $this->flutterwave = $flutterwave;
    }

    // ---------------------------------------------------------------- listing

    public function withdrawals(User $user)
    {
        return $this->scoped(Withdrawal::with('user', 'transaction', 'payment', 'bank'), $user)->orderBy('created_at')->get();
    }

    public function refunds(User $user)
    {
        return $this->scoped(Refund::with('user', 'transaction', 'payment', 'bank'), $user)->orderBy('created_at')->get();
    }

    public function referralWithdrawals(User $user)
    {
        return $this->scoped(ReferralWidrawal::with('user', 'bank'), $user)->orderBy('status')->get();
    }

    // --------------------------------------------------------------- requests

    /**
     * A seller asks for the proceeds of a completed transaction.
     */
    public function requestWithdrawal(User $user, array $data): Withdrawal
    {
        $transaction = $this->transaction($data['transaction_id']);
        $payment = $this->paymentFor($transaction, $data['payment_id']);

        if (! $this->isSeller($transaction, $user)) {
            throw PayoutException::notEntitled();
        }

        if ($transaction->status != TransactionService::STATUS_COMPLETED) {
            throw PayoutException::notEligible('The transaction must be completed before you can withdraw');
        }

        if (! $payment->verified || $payment->withdrawn) {
            throw PayoutException::notEligible('This payment can not be withdrawn');
        }

        if (Withdrawal::where('transaction_id', $transaction->id)->orWhere('payment_id', $payment->id)->exists()) {
            throw PayoutException::alreadyRequested('withdrawal');
        }

        $bank = $this->ownBank($user, $data['bank_id']);

        $withdrawal = Withdrawal::create([
            'user_id' => $user->id,
            'transaction_id' => $transaction->id,
            'payment_id' => $payment->id,
            'bank_id' => $bank->id,
            'narration' => $data['narration'] ?? null,
            'debit_currency' => $data['debit_currency'] ?? 'NGN',
            'status' => false,
        ]);

        Mail::to($user->email)->send(new RequestWithdrawal($user->name, $transaction->id));
        $this->notifyStaff(new RequestWithdrawal('Admin', $transaction->id));

        return $withdrawal;
    }

    /**
     * A buyer asks for their money back on a cancelled or disputed transaction.
     */
    public function requestRefund(User $user, array $data): Refund
    {
        $transaction = $this->transaction($data['transaction_id']);
        $payment = $this->paymentFor($transaction, $data['payment_id']);

        if ($payment->user_id != $user->id) {
            throw PayoutException::notEntitled();
        }

        $refundable = in_array($transaction->status, [TransactionService::STATUS_CANCELLED, TransactionService::STATUS_EXPIRED_REPORT])
            || $transaction->dispute;

        if (! $refundable) {
            throw PayoutException::notEligible('This transaction is not eligible for a refund');
        }

        if (! $payment->verified || $payment->refund || $payment->withdrawn) {
            throw PayoutException::notEligible('This payment can not be refunded');
        }

        if (Refund::where('transaction_id', $transaction->id)->orWhere('payment_id', $payment->id)->exists()) {
            throw PayoutException::alreadyRequested('refund');
        }

        $bank = $this->ownBank($user, $data['bank_id']);

        return Refund::create([
            'user_id' => $user->id,
            'transaction_id' => $transaction->id,
            'payment_id' => $payment->id,
            'bank_id' => $bank->id,
            'narration' => $data['narration'] ?? null,
            'debit_currency' => $data['debit_currency'] ?? 'NGN',
            'status' => false,
        ]);
    }

    /**
     * Cash out all referral bonuses once they add up to the minimum.
     */
    public function requestReferralWithdrawal(User $user, array $data = []): ReferralWidrawal
    {
        return DB::transaction(function () use ($user, $data) {
            $bonuses = Referer::where('user_id', $user->id)
                ->where('withdrawal_status', false)
                ->where('transaction_status', true)
                ->where('amount', '>', 0)
                ->lockForUpdate()
                ->get();

            $total = $bonuses->sum('amount');

            if ($total < self::MIN_REFERRAL_PAYOUT) {
                throw PayoutException::belowMinimum(self::MIN_REFERRAL_PAYOUT);
            }

            $bank = Bank::where('user_id', $user->id)->first();

            if (! $bank) {
                throw PayoutException::noBank();
            }

            $withdrawal = ReferralWidrawal::create([
                'user_id' => $user->id,
                'bank_id' => $bank->id,
                'amount' => $total,
                'narration' => $data['narration'] ?? 'Payment for referral bonus',
                'debit_currency' => $data['debit_currency'] ?? 'NGN',
                'status' => false,
            ]);

            Referer::whereIn('id', $bonuses->pluck('id'))->update(['withdrawal_status' => true]);

            return $withdrawal;
        });
    }

    // -------------------------------------------------------------- transfers

    public function transferWithdrawal(int $id): array
    {
        $withdrawal = $this->pending(Withdrawal::with('transaction.product', 'bank'), $id);

        $amount = $withdrawal->transaction->amount;
        $result = $this->send($withdrawal->bank, $amount, $withdrawal->narration ?: 'Paylidate withdrawal');

        DB::transaction(function () use ($withdrawal, $result) {
            $withdrawal->update(['status' => true, 'f_withdrawal_id' => $result['id']]);
            Payment::where('id', $withdrawal->payment_id)->update(['withdrawn' => true]);
        });

        return $result;
    }

    public function transferRefund(int $id): array
    {
        $refund = $this->pending(Refund::with('transaction.product', 'bank'), $id);

        $transaction = $refund->transaction;
        $amount = $transaction->quantity * $transaction->product->price;
        $result = $this->send($refund->bank, $amount, $refund->narration ?: 'Paylidate refund');

        DB::transaction(function () use ($refund, $result) {
            $refund->update(['status' => true, 'f_withdrawal_id' => $result['id']]);
            Payment::where('id', $refund->payment_id)->update(['refund' => true]);
        });

        return $result;
    }

    public function transferReferralWithdrawal(int $id): array
    {
        $withdrawal = $this->pending(ReferralWidrawal::with('bank'), $id);

        $result = $this->send($withdrawal->bank, $withdrawal->amount, $withdrawal->narration ?: 'Payment for referral bonus');

        DB::transaction(function () use ($withdrawal, $result) {
            $withdrawal->update(['status' => true, 'f_withdrawal_id' => $result['id']]);
            Referer::where('user_id', $withdrawal->user_id)
                ->where('withdrawal_status', true)
                ->where('transfer_status', false)
                ->update(['transfer_status' => true]);
        });

        return $result;
    }

    // ---------------------------------------------------------------- helpers

    protected function scoped($query, User $user)
    {
        return $user->is_admin ? $query : $query->where('user_id', $user->id);
    }

    protected function transaction($id): Transaction
    {
        $transaction = Transaction::with('product')->find($id);

        if (! $transaction) {
            throw PayoutException::notFound('Transaction');
        }

        return $transaction;
    }

    protected function paymentFor(Transaction $transaction, $paymentId): Payment
    {
        $payment = Payment::where('id', $paymentId)->where('transaction_id', $transaction->id)->first();

        if (! $payment) {
            throw PayoutException::notFound('Payment');
        }

        return $payment;
    }

    protected function ownBank(User $user, $bankId): Bank
    {
        $bank = Bank::where('id', $bankId)->where('user_id', $user->id)->first();

        if (! $bank) {
            throw PayoutException::noBank();
        }

        return $bank;
    }

    /**
     * The seller is the product owner on "sell" listings and the invited user on "buy" listings.
     */
    protected function isSeller(Transaction $transaction, User $user): bool
    {
        $product = $transaction->product;

        return ($product->transaction_type == 'sell' && $product->user_id == $user->id)
            || ($product->transaction_type == 'buy' && $transaction->user_id == $user->id);
    }

    protected function pending($query, int $id)
    {
        $request = $query->lockForUpdate()->find($id);

        if (! $request) {
            throw PayoutException::notFound();
        }

        if ($request->status) {
            throw PayoutException::alreadyPaid();
        }

        return $request;
    }

    /**
     * Transfer money from the platform account to a stored bank account.
     *
     * @return array the provider's `data` object (contains the transfer id)
     */
    protected function send(Bank $bank, $amount, string $narration): array
    {
        $response = $this->flutterwave->transfer_to_bank([
            'account_bank' => $bank->bank_code,
            'account_number' => $bank->account_number,
            'amount' => $amount,
            'narration' => $narration,
        ]);

        if (($response['status'] ?? null) != 'success') {
            throw PayoutException::providerFailed($response['message'] ?? '');
        }

        return $response['data'];
    }

    protected function notifyStaff($mail): void
    {
        $recipients = config('app.admin_emails');

        if (! empty($recipients)) {
            Mail::to($recipients)->send($mail);
        }
    }
}
