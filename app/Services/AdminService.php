<?php

namespace App\Services;

use App\Dispute;
use App\Referer;
use App\Refund;
use App\Transaction;
use App\User;
use App\Wallet;

/**
 * Read-only reporting for the admin dashboard.
 */
class AdminService
{
    public function users()
    {
        return User::with('products')->orderBy('name', 'ASC')->get();
    }

    public function usersBetween(string $startDate, string $endDate)
    {
        return User::whereBetween('created_at', [$startDate, $endDate])->get();
    }

    public function overview(): array
    {
        $completed = Transaction::where('status', TransactionService::STATUS_COMPLETED);

        $disputes = Dispute::with('user', 'transaction')->orderBy('dispute_solved')->get();
        $receivers = User::whereIn('id', $disputes->pluck('transaction.user_id')->filter())->get()->keyBy('id');

        foreach ($disputes as $dispute) {
            $dispute->receiver_name = optional($receivers->get(optional($dispute->transaction)->user_id))->name;
        }

        return [
            'Total_registered_users' => User::count(),
            'Total_Transactions_completed' => (clone $completed)->count(),
            'Total_Transactions_completed_amount' => (clone $completed)->sum('amount'),
            'Total_Dispute' => Dispute::count(),
            'Total_Refund' => Refund::count(),
            'Total_Referral' => Referer::count(),
            'total_Wallet_Amount' => Wallet::sum('balance'),
            'list_Of_Users' => User::get(),
            'list_Of_Transactions' => Transaction::with('product', 'payment', 'referral')->orderBy('created_at', 'desc')->get(),
            'list_Of_Disputes' => $disputes,
        ];
    }
}
