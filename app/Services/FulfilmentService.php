<?php

namespace App\Services;

use App\Exceptions\AppException;
use App\Fulfillment;
use App\Transaction;
use App\User;
use App\Mail\FulfilmentMail;
use App\Mail\SellerFulfilmentMail;
use Illuminate\Support\Facades\Mail;

class FulfilmentService
{
    /**
     * @param array<string, mixed> $transaction_details
     */
    public function initiate_fufilment(
        string $seller_mail,
        string $seller_name,
        string $buyer_mail,
        string $buyer_name,
        int $buyer_id,
        int $t_id,
        string $t_ref,
        array $transaction_details
    ): bool {
        try {
            $code = $this->generateCode();

            Fulfillment::create([
                'user_id' => $buyer_id,
                'transaction_id' => $t_id,
                'transaction_ref' => $t_ref,
                'code' => $code,
                'status' => Fulfillment::PENDING
            ]);

            Mail::to($buyer_mail)->send(new FulfilmentMail($buyer_name, (string) $code, $transaction_details));
            Mail::to($seller_mail)->send(new SellerFulfilmentMail($seller_name, $this->url($buyer_id, $t_id), $transaction_details));

            return true;
        } catch (\Throwable $exception) {
            report($exception);
            throw $exception;
        }
    }

    protected function generateCode(): int
    {
        do {
            $number = mt_rand(10000, 99999);
        } while (Fulfillment::where('code', $number)->exists());

        return $number;
    }

    protected function url(int $buyer_id, int $trx_id): string
    {
        return 'paylidate.com/fulfillment/' . base64_encode($buyer_id . ':' . $trx_id);
    }

    /**
     * Decode the buyer/transaction pair out of a fulfilment link hash.
     *
     * @return array{0: int, 1: int} buyer id and transaction id
     *
     * @throws AppException
     */
    public function parseHash(string $hash): array
    {
        $decoded = base64_decode($hash, true);
        $parts = $decoded === false ? [] : explode(':', $decoded);

        if (count($parts) !== 2 || ! ctype_digit($parts[0]) || ! ctype_digit($parts[1])) {
            throw new AppException('This fulfilment link is invalid', 404, 'fulfilment_link_invalid');
        }

        return [(int) $parts[0], (int) $parts[1]];
    }

    /**
     * What the seller sees when opening the fulfilment link.
     *
     * @return array<string, mixed>
     */
    public function details(string $hash): array
    {
        [$buyerId, $transactionId] = $this->parseHash($hash);

        $transaction = Transaction::with('product.user')->find($transactionId);
        $fulfilment = Fulfillment::where('transaction_id', $transactionId)->where('user_id', $buyerId)->first();

        $product = $transaction?->product;

        if (! $transaction || ! $fulfilment || ! $product) {
            throw new AppException('This fulfilment link is invalid', 404, 'fulfilment_link_invalid');
        }

        return [
            'product_number' => $product->product_number,
            'product_name' => $product->name,
            'product_description' => $product->description ?: 'No description',
            'sellers_name' => optional($product->user)->name,
            'buyers_name' => optional(User::find($buyerId))->only('name'),
            'buyers_s_d' => $transaction->description ?: 'No description',
        ];
    }

    /**
     * Complete the order when the buyer's code is presented.
     *
     * @throws AppException
     */
    public function confirm(string $hash, string|int $code): void
    {
        [$buyerId, $transactionId] = $this->parseHash($hash);

        $fulfilment = Fulfillment::where('transaction_id', $transactionId)->where('user_id', $buyerId)->first();

        if (! $fulfilment) {
            throw new AppException('This fulfilment link is invalid', 404, 'fulfilment_link_invalid');
        }

        if ($fulfilment->status == Fulfillment::SUCCESSFUL) {
            throw new AppException('This Order Has Already Been FulfFilled', 409, 'fulfilment_already_used');
        }

        if (! hash_equals((string) $fulfilment->code, (string) $code)) {
            throw new AppException('Invalid Code', 400, 'fulfilment_code_invalid');
        }

        $fulfilment->update(['status' => Fulfillment::SUCCESSFUL]);
        Transaction::where('id', $transactionId)->update(['status' => TransactionService::STATUS_COMPLETED]);
    }
}
