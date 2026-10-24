<?php

namespace App\Services;

use App\Exceptions\AppException;
use App\Exceptions\PaymentVerificationException;
use App\Payment;
use App\Referer;
use App\Transaction;
use App\User;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;

class PaymentService
{
    protected FlutterwaveService $flutterwave;

    protected TransactionService $transactions;

    public function __construct(FlutterwaveService $flutterwave, TransactionService $transactions)
    {
        $this->flutterwave = $flutterwave;
        $this->transactions = $transactions;
    }

    /**
     * Split the value of a purchase between the seller and the referrer.
     *
     * @return array{amount: float|int, referral_amount: float|int}
     */
    public static function splitAmount(int|float $price, int|float $referralAmount, int $quantity): array
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
    public function exists(int|string $paymentId): bool
    {
        return Payment::where('payment_id', $paymentId)->exists();
    }

    /**
     * Record a payment after Flutterwave confirms it, reduce the product stock and settle the
     * seller/referrer amounts on the related transaction.
     *
     * The caller must be the buyer, the payment must be successful and cover the price, and a
     * payment id can only ever be recorded once.
     *
     * @param array{
     *     transaction_id: int|string,
     *     payment_id: int|string,
     *     payment_ref?: string|null,
     *     description?: string|null
     * } $attributes
     *
     * @throws PaymentVerificationException
     */
    public function recordVerifiedPayment(int $userId, array $attributes): Payment
    {
        $buyer = User::findOrFail($userId);
        $reference = (string) ($attributes['payment_ref'] ?? $attributes['payment_id']);

        return DB::transaction(function () use ($buyer, $attributes, $reference) {
            // Serialise payments for one transaction so a double submit cannot record twice.
            $transaction = Transaction::with('product')->lockForUpdate()->find($attributes['transaction_id']);

            if (! $transaction || ! $transaction->product) {
                throw PaymentVerificationException::failed();
            }

            if (! $this->transactions->isBuyer($transaction, $buyer)) {
                throw PaymentVerificationException::notBuyer();
            }

            if ($this->exists($attributes['payment_id']) || Payment::where('transaction_id', $transaction->id)->where('verified', true)->exists()) {
                throw PaymentVerificationException::alreadyVerified();
            }

            $product = $transaction->product;

            if ($product->price === null) {
                throw PaymentVerificationException::failed();
            }

            $expected = $product->price * $transaction->quantity;
            $this->assertPaid($reference, $expected);

            $payment = Payment::create([
                'user_id' => $buyer->id,
                'payment_ref' => $attributes['payment_ref'] ?? null,
                'payment_id' => $attributes['payment_id'],
                'transaction_id' => $transaction->id,
                'verified' => true,
                'description' => $attributes['description'] ?? null,
            ]);

            $split = self::splitAmount($product->price, $product->referral_amount ?? 0, (int) $transaction->quantity);

            $product->update(['quantity' => $product->quantity - $transaction->quantity]);
            $transaction->update(['amount' => $split['amount']]);

            Referer::where('id', $transaction->referer_id)
                ->update(['amount' => $split['referral_amount']]);

            return $payment;
        });
    }

    /**
     * Ask Flutterwave whether $reference was paid successfully, in NGN, for at least $expected.
     *
     * @throws PaymentVerificationException
     */
    protected function assertPaid(string $reference, int|float $expected): void
    {
        $data = $this->flutterwave->verify_payment($reference)['data'] ?? [];

        $paid = ($data['status'] ?? null) === 'successful'
            && ($data['currency'] ?? 'NGN') === 'NGN'
            && (float) ($data['amount'] ?? 0) + 0.005 >= (float) $expected;

        if (! $paid) {
            throw PaymentVerificationException::failed();
        }
    }

    /**
     * Build the Flutterwave card charge payload for $user.
     *
     * @param array<string, mixed> $input
     * @return array<string, mixed>
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
     * @param array<string, mixed> $input
     * @return array<string, mixed> the Flutterwave `data` object (contains `authurl` or the next auth step)
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

    /** @return Collection<int, Payment> */
    public function listFor(User $user): Collection
    {
        return Payment::where('user_id', $user->id)->with('product')->get();
    }

    /**
     * One of the user's own payments.
     *
     * @throws \Illuminate\Database\Eloquent\ModelNotFoundException
     */
    public function findFor(User $user, int $id): Payment
    {
        return Payment::where('user_id', $user->id)->with('product')->findOrFail($id);
    }

    public function describe(User $user, int $id, ?string $description): Payment
    {
        $payment = $this->findFor($user, $id);
        $payment->update(['description' => $description]);

        return $payment;
    }

    public function remove(User $user, int $id): void
    {
        $this->findFor($user, $id)->delete();
    }
}
