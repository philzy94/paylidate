<?php

namespace Tests\Feature;

use App\Bank;
use App\Mail\RequestWithdrawal;
use App\Payment;
use App\Product;
use App\ReferralWidrawal;
use App\Refund;
use App\Transaction;
use App\User;
use App\Withdrawal;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Laravel\Passport\Passport;
use Tests\TestCase;

class PayoutEdgeCasesTest extends TestCase
{
    use RefreshDatabase;

    private function signIn(User $user): User
    {
        Passport::actingAs($user, [], 'api');

        return $user;
    }

    private function bank(User $user): Bank
    {
        return Bank::create([
            'user_id' => $user->id,
            'bank_name' => 'GTB',
            'account_number' => '0123456789',
            'bank_code' => '058',
            'account_name' => $user->name,
            'branch_name' => 'null',
        ]);
    }

    /** @return array{0: User, 1: User, 2: Transaction, 3: Payment} seller, buyer, transaction, payment */
    private function paidSale(array $transaction = [], array $payment = []): array
    {
        $seller = User::factory()->create();
        $buyer = User::factory()->create();
        $product = Product::factory()->create([
            'user_id' => $seller->id,
            'transaction_type' => 'sell',
            'price' => 1000,
            'referral_amount' => 0,
        ]);
        $tx = Transaction::create(array_merge([
            'user_id' => $buyer->id,
            'product_id' => $product->id,
            'quantity' => 2,
            'status' => 1,
            'amount' => 1800,
        ], $transaction));
        $paid = Payment::factory()->create(array_merge([
            'user_id' => $buyer->id,
            'transaction_id' => $tx->id,
            'verified' => true,
        ], $payment));

        return [$seller, $buyer, $tx, $paid];
    }

    private function payload(Transaction $tx, Payment $payment, Bank $bank): array
    {
        return [
            'transaction_id' => $tx->id,
            'payment_id' => $payment->id,
            'bank_id' => $bank->id,
            'narration' => 'Payout',
            'debit_currency' => 'NGN',
        ];
    }

    public function testUsersSeeOnlyTheirRefundsAndReferralWithdrawalsWhileAdminsSeeAll()
    {
        [, $buyer, $tx, $payment] = $this->paidSale(['status' => 2]);
        [, $other, $otherTx, $otherPayment] = $this->paidSale(['status' => 2]);
        $bank = $this->bank($buyer);
        $otherBank = $this->bank($other);
        Refund::create(['user_id' => $buyer->id, 'transaction_id' => $tx->id, 'payment_id' => $payment->id, 'bank_id' => $bank->id]);
        Refund::create(['user_id' => $other->id, 'transaction_id' => $otherTx->id, 'payment_id' => $otherPayment->id, 'bank_id' => $otherBank->id]);
        ReferralWidrawal::create(['user_id' => $buyer->id, 'bank_id' => $bank->id, 'amount' => 3000, 'status' => false]);
        ReferralWidrawal::create(['user_id' => $other->id, 'bank_id' => $otherBank->id, 'amount' => 4000, 'status' => false]);

        $this->signIn($buyer);
        $this->getJson('/api/refund-requests')->assertOk()->assertJsonCount(1, 'data');
        $this->getJson('/api/referral-withdraw-requests')->assertOk()->assertJsonCount(1, 'data');

        $this->signIn(User::factory()->create(['is_admin' => true]));
        $this->getJson('/api/refund-requests')->assertOk()->assertJsonCount(2, 'data');
        $this->getJson('/api/referral-withdraw-requests')->assertOk()->assertJsonCount(2, 'data');
    }

    public function testAnUnverifiedPaymentCannotBeWithdrawn()
    {
        [$seller, , $tx, $payment] = $this->paidSale([], ['verified' => false]);
        $this->signIn($seller);

        $this->postJson('/api/request-withdrawal', $this->payload($tx, $payment, $this->bank($seller)))
            ->assertStatus(422)
            ->assertJsonPath('message', 'This payment can not be withdrawn');
    }

    public function testAnAlreadyWithdrawnPaymentCannotBeWithdrawnAgain()
    {
        [$seller, , $tx, $payment] = $this->paidSale([], ['withdrawn' => true]);
        $this->signIn($seller);

        $this->postJson('/api/request-withdrawal', $this->payload($tx, $payment, $this->bank($seller)))
            ->assertStatus(422)
            ->assertJsonPath('code', 'payout_not_eligible');
    }

    public function testAWithdrawalForAnUnknownTransactionIsNotFound()
    {
        [$seller, , , $payment] = $this->paidSale();
        $this->signIn($seller);

        $this->postJson('/api/request-withdrawal', [
            'transaction_id' => 999999,
            'payment_id' => $payment->id,
            'bank_id' => $this->bank($seller)->id,
            'narration' => 'Payout',
            'debit_currency' => 'NGN',
        ])->assertStatus(404)->assertJsonPath('code', 'payout_not_found');
    }

    public function testAWithdrawalForATransactionWhoseProductIsGoneIsForbidden()
    {
        [$seller, , $tx, $payment] = $this->paidSale();
        Product::query()->whereKey($tx->product_id)->delete();
        $this->signIn($seller);

        $this->postJson('/api/request-withdrawal', $this->payload($tx, $payment, $this->bank($seller)))
            ->assertStatus(403)
            ->assertJsonPath('code', 'payout_forbidden');
    }

    public function testARefundCannotBeRequestedTwice()
    {
        Mail::fake();
        [, $buyer, $tx, $payment] = $this->paidSale(['status' => 2]);
        $this->signIn($buyer);
        $payload = $this->payload($tx, $payment, $this->bank($buyer));

        $this->postJson('/api/request-refund', $payload)->assertOk();
        $this->postJson('/api/request-refund', $payload)
            ->assertStatus(422)
            ->assertJsonPath('code', 'payout_already_requested');
    }

    public function testAPaymentThatWasAlreadyRefundedOrWithdrawnCannotBeRefunded()
    {
        [, $buyer, $tx, $payment] = $this->paidSale(['status' => 2], ['withdrawn' => true]);
        $this->signIn($buyer);

        $this->postJson('/api/request-refund', $this->payload($tx, $payment, $this->bank($buyer)))
            ->assertStatus(422)
            ->assertJsonPath('message', 'This payment can not be refunded');
    }

    public function testStaffAreEmailedWhenAdminAddressesAreConfigured()
    {
        Mail::fake();
        config(['app.admin_emails' => ['ops@example.test']]);
        [$seller, , $tx, $payment] = $this->paidSale();
        $this->signIn($seller);

        $this->postJson('/api/request-withdrawal', $this->payload($tx, $payment, $this->bank($seller)))->assertOk();

        Mail::assertSent(RequestWithdrawal::class, fn ($mail) => $mail->hasTo('ops@example.test'));
        Mail::assertSent(RequestWithdrawal::class, fn ($mail) => $mail->hasTo($seller->email));
    }

    public function testATransferForAnUnknownRequestIsNotFound()
    {
        Http::fake();
        $this->signIn(User::factory()->create(['is_admin' => true]));

        $this->postJson('/api/transfer-to-bank', ['id' => 999999])
            ->assertStatus(404)
            ->assertJsonPath('code', 'payout_not_found');

        Http::assertNothingSent();
    }

    public function testAWithdrawalTransferNeedsTheTransactionAmount()
    {
        Http::fake();
        [$seller, , $tx, $payment] = $this->paidSale(['amount' => null]);
        $withdrawal = Withdrawal::create([
            'user_id' => $seller->id, 'transaction_id' => $tx->id, 'payment_id' => $payment->id, 'bank_id' => $this->bank($seller)->id,
        ]);
        $this->signIn(User::factory()->create(['is_admin' => true]));

        $this->postJson('/api/transfer-to-bank', ['id' => $withdrawal->id])
            ->assertStatus(422)
            ->assertJsonPath('message', 'The payout amount has not been set');

        Http::assertNothingSent();
    }

    public function testARefundTransferNeedsTheProductPrice()
    {
        Http::fake();
        [, $buyer, $tx, $payment] = $this->paidSale(['status' => 2]);
        $refund = Refund::create([
            'user_id' => $buyer->id, 'transaction_id' => $tx->id, 'payment_id' => $payment->id, 'bank_id' => $this->bank($buyer)->id,
        ]);
        Product::query()->whereKey($tx->product_id)->delete();
        $this->signIn(User::factory()->create(['is_admin' => true]));

        $this->postJson('/api/transfer-to-buyer-bank', ['id' => $refund->id])
            ->assertStatus(422)
            ->assertJsonPath('message', 'The refund amount has not been set');

        Http::assertNothingSent();
    }
}
