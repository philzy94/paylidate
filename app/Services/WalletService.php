<?php

namespace App\Services;

use App\Exceptions\WalletException;
use App\Mail\WalletCreated;
use App\User;
use App\WalletsettlementId;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;
use Illuminate\Support\Facades\Http;
use App\Services\FlutterwaveService;
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
     * Shape returned by the legacy array-style methods when the user has no wallet.
     *
     * @return array<string, mixed>
     */
    protected function missingWallet(): array
    {
        return [
            'status' => 'error',
            'message' => "You don't have a wallet",
        ];
    }

    /**
     * Create a virtual account and a user wallet.
     *
     * @return Wallet|array<string, mixed>
     */
    public function createWallet(int $user_id, string $email, string $name, string $bvn): Wallet|array
    {
        $tx_ref = 'PD' . '_' . $user_id . time();
        try {
            $accountResponse = $this->FlutterwaveService->createVirtualAccount($email, $name, $tx_ref, $bvn); // creating a virtual account number
            if ($accountResponse->status !== 'success') {
                return [
                    'status' => 'error',
                    'message' => $accountResponse->message,

                ];
            }
            $accountResponse = $accountResponse->data;
            $response = Wallet::create([
                'user_id' => $user_id,
                'tx_ref' => $tx_ref,
                'order_ref' => $accountResponse->order_ref,
                'account_number' => $accountResponse->account_number,
                'bank_name' => $accountResponse->bank_name,
                'bonus' => 1000.00,
            ]);
            return $response;
        } catch (\Exception $e) {
            report($e);
            return [
                'status' => 'error',
                'message' => 'An error occured. Please try again'
            ];
        }
    }

    /**
     * Credit a wallet by user id.
     *
     * @return array<string, mixed>
     */
    public function creditWalletById(int $user_id, int|float $amount): array
    {
        try {
            if ($amount < 1) {
                return [
                    'status' => 'error',
                    'message' => 'amount must be greater than 0'
                ];
            }
            $wallet = DB::transaction(function () use ($user_id, $amount) {
                $wallet = Wallet::where('user_id', $user_id)->lockForUpdate()->firstOrFail();
                $wallet->update(['balance' => $wallet->balance + $amount]);

                return $wallet;
            });

            return [
                'status' => 'success',
                'data' => $wallet
            ];
        } catch (\Exception $e) {
            report($e);
            return [
                'status' => 'error',
                'message' => 'An error occured. Please try again'
            ];
        }
    }

    /**
     * Credit a wallet by its transaction ref.
     *
     * @return array<string, mixed>
     */
    public function creditWalletBytx_ref(string $tx_ref, int|float $amount): array
    {
        try {
            $wallet = Wallet::where('tx_ref', $tx_ref)->first();

            if (! $wallet) {
                return $this->missingWallet();
            }

            $balance =  $wallet->balance + $amount;
            $wallet->update([
                'balance' => $balance
            ]);

            return [
                'status' => 'success',
                'data' => $wallet
            ];
        } catch (\Exception $e) {
            report($e);
            return [
                'status' => 'error',
                'message' => 'An error occured. Please try again'
            ];
        }
    }


    /**
     * Debit a wallet by user id.
     *
     * @return array<string, mixed>
     */
    public function debitWallet(int $user_id, int|float $amount): array
    {
        try {
            if ($amount < 1) {
                return [
                    'status' => 'error',
                    'message' => 'amount must be greater than 0 NGN'
                ];
            }
            return DB::transaction(function () use ($user_id, $amount) {
                $wallet = Wallet::where('user_id', $user_id)->lockForUpdate()->first();

                if (!$wallet) {
                    return [
                        'status' => 'error',
                        'message' => "You don't have a wallet"
                    ];
                }

                if ($wallet->balance - $amount < 0) {
                    return [
                        'status' => 'error',
                        'message' => 'insufficient fund'
                    ];
                }

                $wallet->update(['balance' => $wallet->balance - $amount]);

                return [
                    'status' => 'success',
                    'data' => $wallet
                ];
            });
        } catch (\Exception $e) {
            report($e);
            return [
                'status' => 'error',
                'message' => 'An error occured. Please try again'
            ];
        }
    }

    /**
     * Transfer wallet bonus to the main balance.
     *
     * @return Wallet|array<string, mixed>
     */
    public function transferBonusToWallet(int $user_id, int|float $amount): Wallet|array
    {
        try {
            if ($amount < 1) {
                return [
                    'status' => 'error',
                    'message' => 'amount must be greater than 0'
                ];
            }
            $wallet = Wallet::where('user_id', $user_id)->first();

            if (! $wallet) {
                return $this->missingWallet();
            }

            if ($wallet->bonus - $amount < 0) {
                return [
                    'status' => 'error',
                    'message' => 'insufficient bonus'
                ];
            }

            if ($wallet->bonus < 3000) {
                return [
                    'status' => 'error',
                    'message' => 'Bonus must be up to 3000 before it can be withdrawn'
                ];
            }

            $balance =  $wallet->balance + $amount;
            $wallet->update([
                'balance' => $balance,
                'bonus' => $wallet->bonus - $amount
            ]);

            return $wallet;
        } catch (\Exception $e) {
            report($e);
            return [
                'status' => 'error',
                'message' => 'An error occured. Please try again'
            ];
        }
    }

    /**
     * Credit the wallet bonus by user id.
     *
     * @return Wallet|array<string, mixed>
     */
    public function creditWalletBonusById(int $user_id, int|float $amount): Wallet|array
    {
        try {
            if ($amount < 1) {
                return [
                    'status' => 'error',
                    'message' => 'amount must be greater than 0'
                ];
            }
            $wallet = Wallet::where('user_id', $user_id)->first();

            if (! $wallet) {
                return $this->missingWallet();
            }

            $wallet->update([
                'bonus' => $wallet->bonus + $amount
            ]);

            return $wallet;
        } catch (\Exception $e) {
            report($e);
            return [
                'status' => 'error',
                'message' => 'An error occured. Please try again'
            ];
        }
    }

    /**
     * Credit the wallet bonus by virtual account number.
     *
     * @return Wallet|array<string, mixed>
     */
    public function creditWalletBonusByAccountNumber(string $account_number, int|float $amount): Wallet|array
    {
        try {
            if ($amount < 1) {
                return [
                    'status' => 'error',
                    'message' => 'amount must be greater than 0'
                ];
            }
            $wallet = Wallet::where('account_number', $account_number)->first();

            if (! $wallet) {
                return $this->missingWallet();
            }

            $wallet->update([
                'bonus' => $wallet->bonus + $amount
            ]);

            return $wallet;
        } catch (\Exception $e) {
            report($e);
            return [
                'status' => 'error',
                'message' => 'An error occured. Please try again'
            ];
        }
    }

    /**
     * Write a wallet history row.
     *
     * @return array<string, mixed>
     */
    public function walletHistory(
        int $user_id,
        string $type,
        int|float $amount,
        string $narration,
        int $wallet_id,
        int|float $balance_before,
        int|float $balance_after
    ): array {
        try {
            $response = WalletHistory::create([
                'user_id' => $user_id,
                'wallet_id' => $wallet_id,
                'type' => $type,
                'amount' => $amount,
                'narration' => $narration,
                'balance_before' => $balance_before,
                'balance_after' => $balance_after,
            ]);
            return [
                'status' => 'success',
                'data' => $response
            ];
        } catch (\Exception $e) {
            report($e);
            return [
                'status' => 'error',
                'message' => 'An error occured. Please try again'
            ];
        }
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

        if (is_array($wallet)) {
            throw WalletException::provider($wallet['message'] ?? '');
        }

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
