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
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Mail\Mailable;
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

    /** @return Collection<int, Withdrawal> */
    public function withdrawals(User $user): Collection
    {
        return $this->scoped(Withdrawal::with('user', 'transaction', 'payment', 'bank'), $user)->orderBy('created_at')->get();
    }

    /** @return Collection<int, Refund> */
    public function refunds(User $user): Collection
    {
        return $this->scoped(Refund::with('user', 'transaction', 'payment', 'bank'), $user)->orderBy('created_at')->get();
    }

    /** @return Collection<int, ReferralWidrawal> */
    public function referralWithdrawals(User $user): Collection
    {
        return $this->scoped(ReferralWidrawal::with('user', 'bank'), $user)->orderBy('status')->get();
    }

    // --------------------------------------------------------------- requests

    /**
     * A seller asks for the proceeds of a completed transaction.
     *
     * @param array{
     *     transaction_id: int|string,
     *     payment_id: int|string,
     *     bank_id: int|string,
     *     narration?: string|null,
     *     debit_currency?: string|null
     * } $data
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

        Mail::to($user->email)->send(new RequestWithdrawal((string) $user->name, $transaction->id));
        $this->notifyStaff(new RequestWithdrawal('Admin', $transaction->id));

        return $withdrawal;
    }

    /**
     * A buyer asks for their money back on a cancelled or disputed transaction.
     *
     * @param array{
     *     transaction_id: int|string,
     *     payment_id: int|string,
     *     bank_id: int|string,
     *     narration?: string|null,
     *     debit_currency?: string|null
     * } $data
     */
    public function requestRefund(User $user, array $data): Refund
    {
        $transaction = $this->transaction($data['transaction_id']);
        $payment = $this->paymentFor($transaction, $data['payment_id']);

        if ($payment->user_id != $user->id) {
            throw PayoutException::notEntitled();
        }

        $this->assertRefundable($transaction, $payment);

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
     * @throws PayoutException when the transaction or payment can not be refunded
     */
    protected function assertRefundable(Transaction $transaction, Payment $payment): void
    {
        $refundable = in_array($transaction->status, [TransactionService::STATUS_CANCELLED, TransactionService::STATUS_EXPIRED_REPORT])
            || $transaction->dispute;

        if (! $refundable) {
            throw PayoutException::notEligible('This transaction is not eligible for a refund');
        }

        if (! $payment->verified || $payment->refund || $payment->withdrawn) {
            throw PayoutException::notEligible('This payment can not be refunded');
        }
    }

    /**
     * Cash out all referral bonuses once they add up to the minimum.
     *
     * @param array{narration?: string|null, debit_currency?: string|null} $data
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

    // ---------------------------------------------------------------- helpers

    /**
     * @template TModel of Model
     *
     * @param Builder<TModel> $query
     * @return Builder<TModel>
     */
    protected function scoped(Builder $query, User $user): Builder
    {
        return $user->is_admin ? $query : $query->where('user_id', $user->id);
    }

    protected function transaction(int|string $id): Transaction
    {
        $transaction = Transaction::with('product')->find($id);

        if (! $transaction) {
            throw PayoutException::notFound('Transaction');
        }

        return $transaction;
    }

    protected function paymentFor(Transaction $transaction, int|string $paymentId): Payment
    {
        $payment = Payment::where('id', $paymentId)->where('transaction_id', $transaction->id)->first();

        if (! $payment) {
            throw PayoutException::notFound('Payment');
        }

        return $payment;
    }

    protected function ownBank(User $user, int|string $bankId): Bank
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

        if (! $product) {
            return false;
        }

        return ($product->transaction_type == 'sell' && $product->user_id == $user->id)
            || ($product->transaction_type == 'buy' && $transaction->user_id == $user->id);
    }

    protected function notifyStaff(Mailable $mail): void
    {
        $recipients = config('app.admin_emails');

        if (! empty($recipients)) {
            Mail::to($recipients)->send($mail);
        }
    }
}
