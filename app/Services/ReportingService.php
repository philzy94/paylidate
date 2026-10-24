<?php

namespace App\Services;

use App\Dispute;
use App\Payment;
use App\Referer;
use App\Refund;
use App\Transaction;
use App\User;
use App\Wallet;
use App\Withdrawal;
use Illuminate\Database\Eloquent\Collection;

/**
 * Per-user figures for the dashboard and the referral screens.
 */
class ReportingService
{
    /** @return array<string, mixed> */
    public function dashboard(User $user): array
    {
        $wallet = Wallet::where('user_id', $user->id)->first();

        return [
            'payments_received' => Transaction::where('user_id', $user->id)->where('status', TransactionService::STATUS_COMPLETED)->sum('amount'),
            'payments_made' => Payment::join('transactions', 'payments.transaction_id', '=', 'transactions.id')
                ->where('payments.user_id', $user->id)
                ->where('transactions.status', TransactionService::STATUS_COMPLETED)
                ->sum('transactions.amount'),
            'balance' => $wallet ? ['balance' => $wallet->balance] : 0,
            'bonus' => $wallet ? ['bonus' => $wallet->bonus] : 0,
            'refer' => Referer::where('user_id', $user->id)->sum('amount'),
            'active_dsiputes' => Dispute::where('user_id', $user->id)->where('dispute_solved', false)->count(),
            'pending_withdrawals' => Withdrawal::where('user_id', $user->id)->where('status', false)->count(),
            'active_transaction' => Transaction::where('user_id', $user->id)->where('status', TransactionService::STATUS_PENDING)->count(),
            'pending_refunds' => Refund::where('user_id', $user->id)->where('status', false)->count(),
        ];
    }

    /** @return Collection<int, Referer> */
    public function bonuses(User $user): Collection
    {
        return Referer::where('user_id', $user->id)
            ->where('amount', '>', 0)
            ->where('transaction_status', true)
            ->with('transaction')
            ->orderBy('withdrawal_status')
            ->orderBy('created_at', 'desc')
            ->get();
    }

    /** @return array<string, mixed> */
    public function referralSummary(User $user): array
    {
        $earned = Referer::where('user_id', $user->id)->where('transaction_status', true);

        return [
            'referral_token' => $user->referral_token,
            'referred_users' => User::where('referred_by', (string) $user->id)->count(),
            'total_earned' => (clone $earned)->sum('amount'),
            'available' => (clone $earned)->where('withdrawal_status', false)->sum('amount'),
            'minimum_payout' => PayoutService::MIN_REFERRAL_PAYOUT,
        ];
    }
}
