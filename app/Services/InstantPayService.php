<?php

namespace App\Services;

use App\Exceptions\InstantPayException;
use App\Instandpay;
use App\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Illuminate\Database\Eloquent\Collection;

/**
 * Send money to another user's phone number; the receiver cashes out to a bank account
 * with a PIN delivered by SMS.
 */
class InstantPayService
{
    protected FlutterwaveService $flutterwave;

    protected SmsService $sms;

    public function __construct(FlutterwaveService $flutterwave, SmsService $sms)
    {
        $this->flutterwave = $flutterwave;
        $this->sms = $sms;
    }

    /** @return Collection<int, Instandpay> */
    public function sent(User $user): Collection
    {
        return Instandpay::where('user_id', $user->id)->orderBy('created_at', 'desc')->get();
    }

    /** @return Collection<int, Instandpay> */
    public function received(User $user): Collection
    {
        return Instandpay::where('receiver_id', $user->id)->orderBy('created_at', 'desc')->get();
    }

    /**
     * Name of the user registered under $phone (for the sender to confirm).
     */
    public function receiverName(User $sender, string $phone): string
    {
        $receiver = $this->receiver($phone);

        if ($receiver->id === $sender->id) {
            throw InstantPayException::toSelf();
        }

        return $receiver->name ?? '';
    }

    /**
     * Record a transfer funded by a Flutterwave payment and notify the receiver by SMS.
     *
     * The amount is taken from Flutterwave's record of the payment, not from the request.
     */
    public function send(User $sender, string $receiverNumber, string $paymentRef, ?string $description = null): Instandpay
    {
        $receiver = $this->receiver($receiverNumber);

        if ($receiver->id === $sender->id) {
            throw InstantPayException::toSelf();
        }

        if (Instandpay::where('payment_ref', $paymentRef)->exists()) {
            throw InstantPayException::paymentUsed();
        }

        $verification = $this->flutterwave->verify_payment($paymentRef);
        $payment = $verification['data'] ?? [];

        if (($payment['status'] ?? null) !== 'successful' || ($payment['currency'] ?? 'NGN') !== 'NGN') {
            throw InstantPayException::paymentUnverified();
        }

        $pin = (string) random_int(100000, 999999);
        $linkToken = 'PD_IP_' . Str::random(4) . date('dmyHis');
        $trackingId = random_int(100000, 999999) . date('dmyHis');

        $transfer = Instandpay::create([
            'user_id' => $sender->id,
            'sender_name' => $sender->name,
            'sender_email' => $sender->email,
            'receiver_id' => $receiver->id,
            'receiver_name' => $receiver->name,
            'receiver_number' => $receiverNumber,
            'amount' => $payment['amount'],
            'payment_ref' => $paymentRef,
            'description' => $description,
            'withdrawal_pin' => Hash::make($pin),
            'link_token' => $linkToken,
            'tracking_id' => $trackingId,
        ]);

        $this->sms->sendToNigerianNumber($receiverNumber, sprintf(
            'Paylidate payment of NGN%s from %s. Visit https://paylidate.com/recieve-instant-funds/%s to withdraw. Your withdrawal pin is %s. Tracking id %s',
            $payment['amount'],
            $sender->name,
            $linkToken,
            $pin,
            $trackingId
        ));

        return $transfer;
    }

    /**
     * The receiver proves they hold the PIN and registers the bank account to pay out to.
     * The account name comes from the bank lookup, not from the client.
     */
    public function claim(User $user, string $linkToken, string $pin, string $bankCode, string $accountNumber): Instandpay
    {
        $transfer = $this->owned($user, $linkToken);

        if (! $this->pinMatches($transfer, $pin)) {
            throw InstantPayException::invalidPin();
        }

        if ($transfer->status) {
            throw InstantPayException::alreadyWithdrawn();
        }

        $lookup = $this->flutterwave->verifyBankAccountNumber($accountNumber, $bankCode);

        if (($lookup['status'] ?? null) !== 'success') {
            throw InstantPayException::accountInvalid();
        }

        $transfer->update([
            'bank_code' => $bankCode,
            'account_number' => $accountNumber,
            'account_name' => $lookup['data']['account_name'] ?? null,
        ]);

        return $transfer;
    }

    /**
     * Pay the money out to the verified bank account. Only the receiver can do this, once.
     */
    public function withdraw(User $user, int $id): Instandpay
    {
        return DB::transaction(function () use ($user, $id) {
            $transfer = Instandpay::lockForUpdate()->find($id);

            if (! $transfer) {
                throw InstantPayException::notFound();
            }

            if ($transfer->receiver_id !== $user->id) {
                throw InstantPayException::forbidden();
            }

            if ($transfer->status) {
                throw InstantPayException::alreadyWithdrawn();
            }

            if (! $transfer->bank_code || ! $transfer->account_number) {
                throw InstantPayException::notReady();
            }

            // Mark as paid first (inside the lock); the transaction rolls back if the transfer fails.
            $transfer->update(['status' => true]);

            $response = $this->flutterwave->transfer_to_bank([
                'account_bank' => $transfer->bank_code,
                'account_number' => $transfer->account_number,
                'amount' => $transfer->amount,
                'narration' => $transfer->description ?: 'Paylidate instant payment',
            ]);

            if (($response['status'] ?? null) !== 'success') {
                throw InstantPayException::transferFailed();
            }

            return $transfer;
        });
    }

    protected function receiver(string $phone): User
    {
        $receiver = strlen($phone) === 11 ? User::where('phone', $phone)->first() : null;

        if (! $receiver) {
            throw InstantPayException::receiverUnknown();
        }

        return $receiver;
    }

    protected function owned(User $user, string $linkToken): Instandpay
    {
        $transfer = Instandpay::where('link_token', $linkToken)->first();

        if (! $transfer) {
            throw InstantPayException::notFound();
        }

        if ($transfer->receiver_id !== $user->id) {
            throw InstantPayException::forbidden();
        }

        return $transfer;
    }

    /**
     * PINs are stored hashed; legacy rows with a plain PIN are still honoured.
     */
    protected function pinMatches(Instandpay $transfer, string $pin): bool
    {
        $stored = (string) $transfer->withdrawal_pin;

        return str_starts_with($stored, '$2y$') ? Hash::check($pin, $stored) : hash_equals($stored, $pin);
    }
}
