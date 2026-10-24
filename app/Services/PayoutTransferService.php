<?php

namespace App\Services;

use App\Bank;
use App\Exceptions\PayoutException;
use App\Payment;
use App\Referer;
use App\ReferralWidrawal;
use App\Refund;
use App\User;
use App\Withdrawal;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;

/**
 * The staff-only half of a payout: sending a stored withdrawal, refund or referral request
 * to the bank. The amount and account come from the stored request, never from the HTTP request.
 */
class PayoutTransferService
{
    protected FlutterwaveService $flutterwave;

    public function __construct(FlutterwaveService $flutterwave)
    {
        $this->flutterwave = $flutterwave;
    }

    /** @return array<string, mixed> */
    public function transferWithdrawal(int $id): array
    {
        $withdrawal = $this->pending(Withdrawal::with('transaction.product', 'bank'), $id);

        $amount = $withdrawal->transaction?->amount;

        if ($amount === null) {
            throw PayoutException::notEligible('The payout amount has not been set');
        }

        $result = $this->send($this->bankOf($withdrawal->bank), $amount, $withdrawal->narration ?: 'Paylidate withdrawal');

        DB::transaction(function () use ($withdrawal, $result) {
            $withdrawal->update(['status' => true, 'f_withdrawal_id' => $result['id']]);
            Payment::where('id', $withdrawal->payment_id)->update(['withdrawn' => true]);
        });

        return $result;
    }

    /** @return array<string, mixed> */
    public function transferRefund(int $id): array
    {
        $refund = $this->pending(Refund::with('transaction.product', 'bank'), $id);

        $transaction = $refund->transaction;
        $price = $transaction?->product?->price;

        if (! $transaction || $price === null) {
            throw PayoutException::notEligible('The refund amount has not been set');
        }

        $amount = $transaction->quantity * $price;
        $result = $this->send($this->bankOf($refund->bank), $amount, $refund->narration ?: 'Paylidate refund');

        DB::transaction(function () use ($refund, $result) {
            $refund->update(['status' => true, 'f_withdrawal_id' => $result['id']]);
            Payment::where('id', $refund->payment_id)->update(['refund' => true]);
        });

        return $result;
    }

    /** @return array<string, mixed> */
    public function transferReferralWithdrawal(int $id): array
    {
        $withdrawal = $this->pending(ReferralWidrawal::with('bank'), $id);

        $result = $this->send(
            $this->bankOf($withdrawal->bank),
            $withdrawal->amount,
            $withdrawal->narration ?: 'Payment for referral bonus'
        );

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

    /**
     * @template TRequest of Withdrawal|Refund|ReferralWidrawal
     *
     * @param Builder<TRequest> $query
     * @return TRequest
     */
    protected function pending(Builder $query, int $id): Model
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
     * @return array<string, mixed> the provider's `data` object (contains the transfer id)
     */
    protected function send(Bank $bank, int|float|string $amount, string $narration): array
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

    protected function bankOf(?Bank $bank): Bank
    {
        return $bank ?? throw PayoutException::noBank();
    }
}
