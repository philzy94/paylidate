<?php

namespace App\Services;

use App\Exceptions\AppException;
use App\Exceptions\PaymentVerificationException;
use App\Payment;
use App\Product;
use App\Referer;
use App\User;
use Illuminate\Support\Facades\DB;

class PaymentService
{
    protected FlutterwaveService $flutterwave;

    public function __construct(FlutterwaveService $flutterwave)
    {
        $this->flutterwave = $flutterwave;
    }

    /**
     * Split the value of a purchase between the seller and the referrer.
     *
     * @return array{amount: float|int, referral_amount: float|int}
     */
    public static function splitAmount($price, $referralAmount, int $quantity): array
    {
        $total = $quantity * $price;
        $referral = $quantity * $referralAmount;

        return [
            'amount' => $total - $referral,
            'referral_amount' => $referral,
        ];
    }

    /**
     * Whether a payment with this id has already been recorded.
     */
    public function exists($paymentId): bool
    {
        return Payment::where('payment_id', $paymentId)->exists();
    }

    /**
     * Record a verified payment, reduce the product stock and settle the
     * seller/referrer amounts on the related transaction.
     */
    public function recordVerifiedPayment(int $userId, array $attributes): Payment
    {
        if ($this->exists($attributes['payment_id'])) {
            throw PaymentVerificationException::alreadyVerified();
        }

        return DB::transaction(function () use ($userId, $attributes) {
            $payment = Payment::create([
                'user_id' => $userId,
                'payment_ref' => $attributes['payment_ref'] ?? null,
                'payment_id' => $attributes['payment_id'],
                'transaction_id' => $attributes['transaction_id'],
                'verified' => true,
                'description' => $attributes['description'] ?? null,
            ]);

            $transaction = $payment->transaction;
            $product = Product::find($transaction->product_id);

            if (! $product) {
                throw PaymentVerificationException::failed();
            }

            $split = self::splitAmount($product->price, $product->referral_amount, (int) $transaction->quantity);

            $product->update(['quantity' => $product->quantity - $transaction->quantity]);
            $transaction->update(['amount' => $split['amount']]);

            Referer::where('id', $transaction->referer_id)
                ->update(['amount' => $split['referral_amount']]);

            return $payment;
        });
    }

    /**
     * Build the Flutterwave card charge payload for $user.
     */
    public function cardPayload(User $user, array $input): array
    {
        $names = explode(' ', (string) $user->name);

        return [
            'PBFPubKey' => config('services.flutterwave.public_key'),
            'cardno' => $input['cardno'] ?? null,
            'currency' => $input['currency'] ?? null,
            'country' => $input['country'] ?? null,
            'cvv' => strval($input['cvv'] ?? ''),
            'amount' => $input['amount'] ?? null,
            'expiryyear' => $input['expiryyear'] ?? null,
            'expirymonth' => $input['expirymonth'] ?? null,
            'pin' => $input['pin'] ?? null,
            'email' => $user->email,
            'phonenumber' => $user->phone,
            'firstname' => $names[0],
            'lastname' => count($names) > 1 ? $names[0] : '',
            'txRef' => 'PD' . $user->id . date('dmyHis'),
            'meta' => ['consumer_id' => $user->name, 'consumer_mac' => ''],
            'redirect_url' => $input['redirect_url'] ?? null,
        ];
    }

    /**
     * Start a card charge, retrying with PIN authorisation when Flutterwave asks for it.
     *
     * @return array the Flutterwave `data` object (contains `authurl` or the next auth step)
     *
     * @throws AppException when the card cannot be charged
     */
    public function initiateCardPayment(User $user, array $input): array
    {
        $payload = $this->cardPayload($user, $input);
        $response = $this->flutterwave->payviacard($payload);

        if (empty($response['data']) || ($response['status'] ?? null) != 'success' || isset($response['data']['code'])) {
            throw new AppException('An error occured while trying to initiate transaction. Please try again', 417, 'payment_initiation_failed');
        }

        $data = $response['data'];

        if (isset($data['authurl']) && $data['authurl'] != 'N/A') {
            return $data;
        }

        $suggestedAuth = $data['suggested_auth'] ?? null;

        if ($suggestedAuth == 'PIN') {
            $payload['suggested_auth'] = 'PIN';
            $retry = $this->flutterwave->payviacard($payload);

            return $retry['data'] ?? [];
        }

        if ($suggestedAuth == 'NOAUTH_INTERNATIONAL') {
            throw new AppException('This card can not be charge', 422, 'card_not_chargeable');
        }

        return $data;
    }
}
