<?php

namespace App\Services;

use App\Exceptions\WalletException;
use App\Mail\WalletCreated;
use App\User;
use App\WalletsettlementId;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use App\Wallet;
use App\WalletHistory;

class WalletService
{
    protected FlutterwaveService $FlutterwaveService;

    public function __construct(FlutterwaveService $flutterwaveService)
    {
        $this->FlutterwaveService = $flutterwaveService;
    }

    /**
     * Create a virtual account and a user wallet.
     *
     * @throws WalletException
     */
    public function createWallet(int $user_id, string $email, string $name, string $bvn): Wallet
    {
        $tx_ref = 'PD_' . $user_id . time();

        try {
            $account = $this->FlutterwaveService->createVirtualAccount($email, $name, $tx_ref, $bvn);
        } catch (\Exception $e) {
            report($e);

            throw WalletException::provider('An error occured. Please try again');
        }

        if ($account->status !== 'success') {
            throw WalletException::provider((string) ($account->message ?? ''));
        }

        return Wallet::create([
            'user_id' => $user_id,
            'tx_ref' => $tx_ref,
            'order_ref' => $account->data->order_ref,
            'account_number' => $account->data->account_number,
            'bank_name' => $account->data->bank_name,
            'bonus' => 1000.00,
        ]);
    }

    /**
     * Credit a wallet by user id.
     *
     * @throws WalletException
     */
    public function creditWalletById(int $user_id, int|float $amount): Wallet
    {
        if ($amount < 1) {
            throw WalletException::invalidAmount();
        }

        return DB::transaction(function () use ($user_id, $amount) {
            $wallet = Wallet::where('user_id', $user_id)->lockForUpdate()->first();

            if (! $wallet) {
                throw WalletException::notFound();
            }

            $wallet->update(['balance' => $wallet->balance + $amount]);

            return $wallet;
        });
    }

    // ------------------------------------------------------------------
    // High level operations used by the controllers. They throw typed
    // exceptions instead of returning status arrays.
    // ------------------------------------------------------------------

    /** @return Collection<int, WalletHistory> */
    public function historyFor(User $user): Collection
    {
        return WalletHistory::where('user_id', $user->id)->orderByDesc('created_at')->get();
    }

    public function walletFor(User $user): ?Wallet
    {
        return Wallet::where('user_id', $user->id)->with('wallet_history')->first();
    }

    /**
     * Open a wallet backed by a Flutterwave virtual account.
     *
     * @throws WalletException
     */
    public function open(User $user, string $bvn): Wallet
    {
        if (Wallet::where('user_id', $user->id)->exists()) {
            throw WalletException::alreadyExists();
        }

        $wallet = $this->createWallet($user->id, $user->email, (string) $user->name, $bvn);

        Mail::to($user->email)->send(new WalletCreated((string) $user->name));

        return $wallet;
    }

    /**
     * Credit the wallet for a card payment, after confirming with Flutterwave that it succeeded.
     * The amount comes from Flutterwave, never from the client.
     *
     * @throws WalletException
     */
    public function topUp(User $user, string $reference, string $narration): WalletHistory
    {
        $wallet = Wallet::where('user_id', $user->id)->first();

        if (! $wallet) {
            throw WalletException::notFound();
        }

        $verification = $this->FlutterwaveService->verify_payment($reference);
        $data = $verification['data'] ?? [];

        if (($data['status'] ?? null) !== 'successful' || ($data['currency'] ?? 'NGN') !== 'NGN') {
            throw WalletException::paymentNotVerified();
        }

        return $this->settle($wallet, $reference, (float) $data['amount'], $narration);
    }

    /**
     * Credit a wallet from a signed Flutterwave transfer webhook.
     *
     * @param array<string, mixed> $payload
     *
     * @throws WalletException
     */
    public function creditFromTransferWebhook(?string $signature, array $payload): void
    {
        $secret = (string) config('services.flutterwave.webhook_hash');

        if ($secret === '' || ! hash_equals($secret, (string) $signature)) {
            throw WalletException::invalidSignature();
        }

        $data = $payload['data'] ?? [];

        if (($data['status'] ?? null) !== 'successful') {
            throw new \App\Exceptions\AppException('Transaction not successful', 400, 'wallet_transfer_unsuccessful');
        }

        $wallet = Wallet::where('tx_ref', $data['tx_ref'] ?? null)->first();
        $amount = (float) ($data['amount'] ?? 0);

        if (! $wallet || $amount < 1) {
            throw WalletException::invalidAmount();
        }

        $this->settle($wallet, (string) $data['id'], $amount, 'Credit wallet by bank transfer');
    }

    /**
     * Spend from the user's own wallet.
     *
     * @throws WalletException
     */
    public function spend(User $user, float $amount, string $narration): WalletHistory
    {
        return DB::transaction(function () use ($user, $amount, $narration) {
            $wallet = Wallet::where('user_id', $user->id)->lockForUpdate()->first();

            if (! $wallet) {
                throw WalletException::notFound();
            }

            if ($amount < 1) {
                throw WalletException::invalidAmount();
            }

            if ($wallet->balance - $amount < 0) {
                throw WalletException::insufficientFunds();
            }

            $before = $wallet->balance;
            $wallet->update(['balance' => $before - $amount]);

            return WalletHistory::create([
                'user_id' => $user->id,
                'wallet_id' => $wallet->id,
                'type' => 'debit',
                'amount' => $amount,
                'narration' => $narration,
                'balance_before' => $before,
                'balance_after' => $before - $amount,
            ]);
        });
    }

    /**
     * Record the settlement id (idempotency key), credit the wallet and write history atomically.
     */
    protected function settle(Wallet $wallet, string $settlementId, float $amount, string $narration): WalletHistory
    {
        if ($amount < 1) {
            throw WalletException::invalidAmount();
        }

        try {
            return DB::transaction(function () use ($wallet, $settlementId, $amount, $narration) {
                // The unique-by-lookup check is repeated inside the transaction so concurrent
                // deliveries of the same webhook cannot both credit the wallet.
                $locked = Wallet::where('id', $wallet->id)->lockForUpdate()->firstOrFail();

                if (WalletsettlementId::where('settlementId', $settlementId)->exists()) {
                    throw WalletException::duplicate();
                }

                WalletsettlementId::create(['wallet_id' => $locked->id, 'settlementId' => $settlementId]);

                $before = $locked->balance;
                $locked->update(['balance' => $before + $amount]);

                return WalletHistory::create([
                    'user_id' => $locked->user_id,
                    'wallet_id' => $locked->id,
                    'type' => 'credit',
                    'amount' => $amount,
                    'narration' => $narration,
                    'balance_before' => $before,
                    'balance_after' => $before + $amount,
                ]);
            });
        } catch (\Illuminate\Database\QueryException $e) {
            // A unique index on the settlement id turns a race into a duplicate error.
            throw WalletException::duplicate();
        }
    }
}
