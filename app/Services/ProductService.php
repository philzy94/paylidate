<?php

namespace App\Services;

use App\Exceptions\ProductException;
use App\Mail\CreateProductMail;
use App\Mail\SellerAcceptTransactionMail;
use App\Product;
use App\Recommendation;
use App\Transaction;
use App\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;
use Illuminate\Database\Eloquent\Collection;

class ProductService
{
    public const DELIVERED = 2;

    /** Attributes a client is allowed to set on a product. */
    protected const EDITABLE = ['name', 'price', 'quantity', 'type', 'description', 'image', 'referral_amount', 'product_status'];

    protected TransactionService $transactions;

    public function __construct(TransactionService $transactions)
    {
        $this->transactions = $transactions;
    }

    /** @return Collection<int, Product> */
    public function listFor(User $user): Collection
    {
        return Product::where('user_id', $user->id)->orderBy('created_at', 'desc')->get();
    }

    /** @param list<string> $with */
    public function findBySlug(string $slug, array $with = []): Product
    {
        $product = Product::where('slug', $slug)->with($with)->first();

        if (! $product) {
            throw ProductException::notFound();
        }

        return $product;
    }

    /** @return Collection<int, Product> */
    public function catalog(int $userId): Collection
    {
        return Product::where('user_id', $userId)->where('product_status', true)->get();
    }

    /**
     * Create a listing. "buy" listings also open a transaction with the invited seller,
     * creating a placeholder account for them if they are not registered yet.
     *
     * @param array<string, mixed> $data
     */
    public function create(User $user, array $data): Product
    {
        $referral = $this->referralFor($data);

        [$product, $transaction, $seller] = DB::transaction(function () use ($user, $data, $referral) {
            $product = Product::create(array_merge(
                array_intersect_key($data, array_flip(array_merge(self::EDITABLE, ['transaction_type']))),
                [
                    'user_id' => $user->id,
                    'slug' => Str::random(5) . date('dmyHis'),
                    'product_number' => date('dmyHis'),
                    'referral_amount' => $referral,
                ]
            ));

            if ($product->transaction_type !== 'buy') {
                return [$product, null, null];
            }

            $seller = $this->sellerFor($data['seller_email']);

            return [$product, $this->openBuyTransaction($product, $seller), $seller];
        });

        if ($transaction && $seller) {
            $this->inviteSeller($user, $seller, $transaction);
        }

        Mail::to($user->email)->send(new CreateProductMail($user, $product));

        return $product;
    }

    /**
     * The referral amount a new listing may carry: none for "buy" listings, and always below the price.
     *
     * @param array<string, mixed> $data
     */
    protected function referralFor(array $data): mixed
    {
        if ($data['transaction_type'] === 'buy') {
            return 0;
        }

        $referral = $data['referral_amount'] ?? 0;

        if (! empty($referral) && $referral >= $data['price']) {
            throw ProductException::referralTooHigh();
        }

        return $referral;
    }

    protected function openBuyTransaction(Product $product, User $seller): Transaction
    {
        $transaction = Transaction::create([
            'product_id' => $product->id,
            'user_id' => $seller->id,
            'quantity' => $product->quantity,
            'transaction_ref' => 'PD_' . Str::random(8) . date('dmyHis'),
        ]);

        return $transaction->load('product');
    }

    protected function inviteSeller(User $buyer, User $seller, Transaction $transaction): void
    {
        Mail::to($seller->email)->send(new SellerAcceptTransactionMail(
            $seller->email,
            $buyer->email,
            $this->transactions->emailDetails($transaction)
        ));
    }

    /** @param array<string, mixed> $data */
    public function update(User $user, int $id, array $data): Product
    {
        $product = $this->owned($user, $id);

        if ($product->transaction_type === 'buy') {
            throw ProductException::buyListing();
        }

        if (! empty($data['referral_amount']) && $data['referral_amount'] >= $data['price']) {
            throw ProductException::referralTooHigh();
        }

        $product->update(array_intersect_key($data, array_flip(self::EDITABLE)));

        return $product;
    }

    public function destroy(User $user, int $id): void
    {
        $product = $this->owned($user, $id);

        if ($product->transaction()->exists()) {
            throw ProductException::hasTransactions();
        }

        $product->delete();
    }

    public function setAvailability(User $user, int $id, bool $available): Product
    {
        $product = $this->owned($user, $id);
        $product->update(['product_status' => $available]);

        return $product;
    }

    /**
     * The seller marks the goods or service as delivered.
     */
    public function markDelivered(User $user, int $id): Product
    {
        $product = Product::with('transaction')->find($id);

        if (! $product) {
            throw ProductException::notFound();
        }

        $isSeller = $product->transaction_type === 'sell'
            ? $product->user_id === $user->id
            : $product->transaction->contains('user_id', $user->id);

        if (! $isSeller) {
            throw ProductException::notOwner();
        }

        $product->update(['delivery_status' => self::DELIVERED]);

        return $product;
    }

    public function recommend(User $user, int $productId): Recommendation
    {
        if (! Product::whereKey($productId)->exists()) {
            throw ProductException::notFound();
        }

        return Recommendation::firstOrCreate(['user_id' => $user->id, 'product_id' => $productId]);
    }

    public function unrecommend(User $user, int $productId): void
    {
        Recommendation::where('user_id', $user->id)->where('product_id', $productId)->delete();
    }

    protected function owned(User $user, int $id): Product
    {
        $product = Product::find($id);

        if (! $product) {
            throw ProductException::notFound();
        }

        if ($product->user_id !== $user->id) {
            throw ProductException::notOwner();
        }

        return $product;
    }

    /**
     * Find the invited seller, or reserve an inactive account they can claim by signing up.
     */
    protected function sellerFor(string $email): User
    {
        $seller = User::where('email', strtolower($email))->first();

        if ($seller) {
            return $seller;
        }

        return User::create([
            'name' => Str::before($email, '@'),
            'email' => $email,
            'password' => Hash::make(Str::random(40)),
            'active' => false,
            'needs_password_reset' => true,
            'referral_token' => Str::random(10) . date('dmyHis'),
        ]);
    }
}
