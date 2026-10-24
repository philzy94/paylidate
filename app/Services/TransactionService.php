<?php

namespace App\Services;

use App\Bank;
use App\Exceptions\TransactionException;
use App\Mail\CreateTransactionMail;
use App\Mail\ReportTransaction;
use App\Product;
use App\Referer;
use App\Refund;
use App\Transaction;
use App\User;
use App\Withdrawal;
use Carbon\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;

/**
 * Business rules for escrow transactions, shared by the first-party and the
 * third-party API controllers.
 */
class TransactionService
{
    public const STATUS_PENDING = 0;
    public const STATUS_COMPLETED = 1;
    public const STATUS_CANCELLED = 2;
    public const STATUS_REPORTED = 3;
    public const STATUS_EXPIRED_REPORT = 4;

    /** Hours a buyer's report stays open before the transaction is marked expired. */
    public const REPORT_WINDOW_HOURS = 24;

    protected FulfilmentService $fulfilmentService;

    public function __construct(FulfilmentService $fulfilmentService)
    {
        $this->fulfilmentService = $fulfilmentService;
    }

    /**
     * Transactions the user is a party to (as initiator or as the product owner).
     *
     * @return Collection<int, Transaction>
     */
    public function listFor(User $user): Collection
    {
        return Transaction::with('product', 'payment')
            ->orderBy('created_at', 'desc')
            ->get()
            ->filter(fn ($transaction) => $this->isInvolved($transaction, $user))
            ->map(function ($transaction) {
                $transaction['referral'] = Referer::find($transaction->referer_id);

                return $transaction;
            })
            ->values();
    }

    /**
     * Create a transaction for $user, optionally crediting a referrer.
     *
     * @param array{
     *     product_id: int|string,
     *     quantity: int,
     *     referral_token?: string|null,
     *     description?: string|null
     * } $data
     *
     * @throws TransactionException
     */
    public function create(User $user, array $data): Transaction
    {
        $product = Product::find($data['product_id']);

        if (! $product) {
            throw TransactionException::notFound();
        }

        if ($data['quantity'] > $product->quantity) {
            throw TransactionException::quantityUnavailable();
        }

        $referralToken = $data['referral_token'] ?? null;

        if ($referralToken && $referralToken == $user->referral_token) {
            throw TransactionException::ownReferral();
        }

        return DB::transaction(function () use ($user, $data, $referralToken) {
            $referralId = null;
            $referrer = $referralToken ? User::where('referral_token', $referralToken)->first() : null;

            if ($referrer) {
                $referralId = Referer::create(['user_id' => $referrer->id, 'amount' => 0.00])->id;
            }

            return Transaction::create([
                'user_id' => $user->id,
                'product_id' => $data['product_id'],
                'quantity' => $data['quantity'],
                'transaction_ref' => 'PD_' . Str::random(8) . date('dmyHis'),
                'referer_id' => $referralId,
                'description' => $data['description'] ?? null,
                'accept_transaction' => true,
            ])->load('product', 'referral');
        });
    }

    /**
     * Email both parties about a new transaction and, optionally, start fulfilment.
     */
    public function notifyCreated(Transaction $transaction, User $buyer, bool $initiateFulfilment = false): void
    {
        $seller = $this->productOf($transaction)->user ?? throw TransactionException::notFound();
        $details = $this->emailDetails($transaction);

        if ($initiateFulfilment) {
            $this->fulfilmentService->initiate_fufilment(
                $seller->email,
                (string) $seller->name,
                $buyer->email,
                (string) $buyer->name,
                $buyer->id,
                $transaction->id,
                (string) $transaction->transaction_ref,
                $details
            );
        }

        Mail::to($buyer->email)->send(new CreateTransactionMail($buyer, $details));
        Mail::to($seller->email)->send(new CreateTransactionMail($seller, $details));
    }

    /**
     * A transaction with everything the client needs to render it.
     *
     * @throws TransactionException
     */
    public function details(string $reference, ?User $viewer = null): Transaction
    {
        $transaction = Transaction::where('transaction_ref', $reference)
            ->with('product', 'payment', 'secondary_user')
            ->first();

        if (! $transaction) {
            throw TransactionException::notFound();
        }

        if ($this->reportHasLapsed($transaction)) {
            $transaction->update(['status' => self::STATUS_EXPIRED_REPORT]);
        }

        $product = $this->productOf($transaction);

        $sellerId = $product->transaction_type == 'sell'
            ? $product->user_id
            : $transaction->user_id;

        $transaction['product_initiator'] = User::find($product->user_id);
        $transaction['seller_email'] = optional(User::find($sellerId))->email;
        $transaction['referral'] = Referer::find($transaction->referer_id);
        $transaction['bank'] = $viewer ? Bank::where('user_id', $viewer->id)->first() : null;
        $transaction['withdrawal_request'] = Withdrawal::where('transaction_id', $transaction->id)->first();
        $transaction['refund'] = Refund::where('transaction_id', $transaction->id)->first();

        return $transaction;
    }

    /**
     * Initiator accepts or declines the transaction.
     *
     * @throws TransactionException
     */
    public function setAcceptance(int $id, User $user, bool $accepted): Transaction
    {
        $transaction = $this->find($id);

        if ($transaction->user_id != $user->id) {
            throw TransactionException::notAllowed();
        }

        $transaction->update(['accept_transaction' => $accepted]);

        return $transaction;
    }

    /**
     * The buyer confirms the goods or service were delivered.
     *
     * @throws TransactionException
     */
    public function confirm(int $id, User $user): Transaction
    {
        $transaction = $this->find($id, ['product']);

        if (! $this->isBuyer($transaction, $user)) {
            throw TransactionException::notAllowed();
        }

        $transaction->update(['status' => self::STATUS_COMPLETED]);

        if ($this->productOf($transaction)->transaction_type == 'sell') {
            Referer::where('id', $transaction->referer_id)->update(['transaction_status' => true]);
        }

        return $transaction;
    }

    /**
     * The buyer reports a problem; staff and the seller are emailed.
     *
     * @throws TransactionException
     */
    public function report(int $id, User $user, string $sellerEmail, string $report): Transaction
    {
        $transaction = $this->find($id, ['product']);

        if (! $this->isBuyer($transaction, $user)) {
            throw TransactionException::notAllowed();
        }

        $transaction->update([
            'status' => self::STATUS_REPORTED,
            'transaction_reported_at' => Carbon::now(),
        ]);

        $seller = User::where('email', $sellerEmail)->first();
        $reference = (string) $transaction->transaction_ref;

        Mail::to($sellerEmail)->send(new ReportTransaction(optional($seller)->name, $reference, 'report', $report));
        $this->notifyStaff(new ReportTransaction('Admin', $reference, 'report', $report));

        return $transaction;
    }

    /**
     * The buyer marks a report as resolved.
     *
     * @throws TransactionException
     */
    public function resolveReport(int $id, User $user, string $sellerEmail): Transaction
    {
        $transaction = $this->find($id, ['product']);

        if (! $this->isBuyer($transaction, $user)) {
            throw TransactionException::notAllowed();
        }

        $transaction->update(['status' => self::STATUS_CANCELLED]);

        $seller = User::where('email', $sellerEmail)->first();
        $reference = (string) $transaction->transaction_ref;

        Mail::to($sellerEmail)->send(new ReportTransaction(optional($seller)->name, $reference, 'resolve'));
        $this->notifyStaff(new ReportTransaction('Admin', $reference, 'resolve'));

        return $transaction;
    }

    /**
     * Cancel the transaction and put the quantity back in stock.
     *
     * @throws TransactionException
     */
    public function cancel(int $id, User $user): Transaction
    {
        $transaction = $this->find($id, ['product']);

        if (! $this->isBuyer($transaction, $user)) {
            throw TransactionException::notAllowed();
        }

        $product = $this->productOf($transaction);

        DB::transaction(function () use ($transaction, $product) {
            $transaction->update(['status' => self::STATUS_CANCELLED]);
            $product->update([
                'quantity' => $product->quantity + $transaction->quantity,
            ]);
        });

        return $transaction;
    }

    /**
     * @param list<string> $with
     */
    protected function find(int $id, array $with = []): Transaction
    {
        $transaction = Transaction::with($with)->find($id);

        if (! $transaction) {
            throw TransactionException::notFound();
        }

        return $transaction;
    }

    /**
     * The product a transaction was made for.
     *
     * @throws TransactionException when the product no longer exists
     */
    protected function productOf(Transaction $transaction): Product
    {
        return $transaction->product ?? throw TransactionException::notFound();
    }

    protected function isInvolved(Transaction $transaction, User $user): bool
    {
        return $transaction->user_id == $user->id || optional($transaction->product)->user_id == $user->id;
    }

    /**
     * The buyer is the product owner on "buy" listings and the initiator on "sell" listings.
     */
    public function isBuyer(Transaction $transaction, User $user): bool
    {
        $product = $this->productOf($transaction);
        $type = $product->transaction_type;

        return ($type == 'buy' && $product->user_id == $user->id)
            || ($type == 'sell' && $transaction->user_id == $user->id);
    }

    protected function reportHasLapsed(Transaction $transaction): bool
    {
        return $transaction->transaction_reported_at
            && $transaction->status == self::STATUS_REPORTED
            && Carbon::parse($transaction->transaction_reported_at)->addHours(self::REPORT_WINDOW_HOURS)->isPast();
    }

    protected function notifyStaff(ReportTransaction $mail): void
    {
        $recipients = config('app.admin_emails');

        if (! empty($recipients)) {
            Mail::to($recipients)->send($mail);
        }
    }

    /**
     * @return array<string, mixed>
     */
    public function emailDetails(Transaction $transaction): array
    {
        $product = $this->productOf($transaction);

        return [
            'id' => $transaction->id,
            'referral' => $transaction->referral,
            'transaction_ref' => $transaction->transaction_ref,
            'product_id' => $transaction->product_id,
            'product_name' => $product->name,
            'product_number' => $product->product_number,
            'type' => $product->type,
            'total_quantity' => $transaction->quantity,
            'total_price' => $product->price * $transaction->quantity,
            'description' => $transaction->description ?: 'No description',
        ];
    }
}
