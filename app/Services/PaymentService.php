<?php

namespace App\Services;

use App\Payment;
use App\Product;
use App\Referer;
use Illuminate\Support\Facades\DB;

class PaymentService
{
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
            $product = Product::findOrFail($transaction->product_id);

            $split = self::splitAmount($product->price, $product->referral_amount, (int) $transaction->quantity);

            $product->update(['quantity' => $product->quantity - $transaction->quantity]);
            $transaction->update(['amount' => $split['amount']]);

            Referer::where('id', $transaction->referer_id)
                ->update(['amount' => $split['referral_amount']]);

            return $payment;
        });
    }
}
