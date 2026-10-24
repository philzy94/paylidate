<?php

namespace Tests\Feature;

use App\Bank;
use App\Payment;
use App\Product;
use App\Referer;
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

class PayoutTest extends TestCase
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

    /** A paid, completed "sell" listing: returns [seller, buyer, transaction, payment]. */
    private function paidSale(array $transaction = []): array
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
        $payment = Payment::factory()->create(['user_id' => $buyer->id, 'transaction_id' => $tx->id, 'verified' => true]);

        return [$seller, $buyer, $tx, $payment];
    }

    private function withdrawalPayload($tx, $payment, $bank): array
    {
        return [
            'transaction_id' => $tx->id,
            'payment_id' => $payment->id,
            'bank_id' => $bank->id,
            'narration' => 'Payout',
            'debit_currency' => 'NGN',
        ];
    }

    // ------------------------------------------------------------ withdrawals

    public function testSellerCanRequestAWithdrawal()
    {
        Mail::fake();
        [$seller, , $tx, $payment] = $this->paidSale();
        $bank = $this->bank($seller);
        $this->signIn($seller);

        $this->postJson('/api/request-withdrawal', $this->withdrawalPayload($tx, $payment, $bank))
            ->assertOk()
            ->assertJsonPath('data.user_id', $seller->id);

        $this->assertDatabaseHas('withdrawals', ['transaction_id' => $tx->id, 'bank_id' => $bank->id, 'status' => false]);
    }

    public function testBuyerCannotWithdrawTheSellersMoney()
    {
        [, $buyer, $tx, $payment] = $this->paidSale();
        $bank = $this->bank($buyer);
        $this->signIn($buyer);

        $this->postJson('/api/request-withdrawal', $this->withdrawalPayload($tx, $payment, $bank))
            ->assertStatus(403)
            ->assertJsonPath('code', 'payout_forbidden');

        $this->assertSame(0, Withdrawal::count());
    }

    public function testWithdrawalRequiresACompletedTransaction()
    {
        [$seller, , $tx, $payment] = $this->paidSale(['status' => 0]);
        $bank = $this->bank($seller);
        $this->signIn($seller);

        $this->postJson('/api/request-withdrawal', $this->withdrawalPayload($tx, $payment, $bank))
            ->assertStatus(422)
            ->assertJsonPath('code', 'payout_not_eligible');
    }

    public function testWithdrawalCannotUseSomeoneElsesBankAccount()
    {
        [$seller, $buyer, $tx, $payment] = $this->paidSale();
        $victimBank = $this->bank($buyer);
        $this->signIn($seller);

        $this->postJson('/api/request-withdrawal', $this->withdrawalPayload($tx, $payment, $victimBank))
            ->assertStatus(422)
            ->assertJsonPath('code', 'payout_no_bank');
    }

    public function testWithdrawalCannotBeRequestedTwice()
    {
        Mail::fake();
        [$seller, , $tx, $payment] = $this->paidSale();
        $bank = $this->bank($seller);
        $this->signIn($seller);
        $payload = $this->withdrawalPayload($tx, $payment, $bank);

        $this->postJson('/api/request-withdrawal', $payload)->assertOk();
        $this->postJson('/api/request-withdrawal', $payload)->assertStatus(422)->assertJsonPath('code', 'payout_already_requested');
    }

    public function testPaymentMustBelongToTheTransaction()
    {
        [$seller, , $tx] = $this->paidSale();
        $otherPayment = Payment::factory()->create();
        $bank = $this->bank($seller);
        $this->signIn($seller);

        $this->postJson('/api/request-withdrawal', $this->withdrawalPayload($tx, $otherPayment, $bank))->assertStatus(404);
    }

    public function testUsersOnlySeeTheirOwnWithdrawals()
    {
        Mail::fake();
        [$seller, , $tx, $payment] = $this->paidSale();
        $bank = $this->bank($seller);
        $this->signIn($seller);
        $this->postJson('/api/request-withdrawal', $this->withdrawalPayload($tx, $payment, $bank))->assertOk();
        [$other, , $tx2, $payment2] = $this->paidSale();
        Withdrawal::create(['user_id' => $other->id, 'transaction_id' => $tx2->id, 'payment_id' => $payment2->id, 'bank_id' => $this->bank($other)->id]);

        $this->getJson('/api/withdraw-requests')->assertOk()->assertJsonCount(1, 'data');

        $this->signIn(User::factory()->create(['is_admin' => true]));
        $this->getJson('/api/withdraw-requests')->assertOk()->assertJsonCount(2, 'data');
    }

    public function testOnlyAdminsCanTriggerATransfer()
    {
        Http::fake();
        [$seller, , $tx, $payment] = $this->paidSale();
        $withdrawal = Withdrawal::create(['user_id' => $seller->id, 'transaction_id' => $tx->id, 'payment_id' => $payment->id, 'bank_id' => $this->bank($seller)->id]);
        $this->signIn($seller);

        $this->postJson('/api/transfer-to-bank', ['id' => $withdrawal->id])->assertStatus(403);

        Http::assertNothingSent();
    }

    public function testAdminTransferUsesStoredAmountAndBankNotRequestInput()
    {
        config(['services.flutterwave.base_url' => 'https://flw.test']);
        Http::fake(['flw.test/*' => Http::response(['status' => 'success', 'message' => 'Transfer Queued', 'data' => ['id' => 4242]])]);
        [$seller, , $tx, $payment] = $this->paidSale();
        $bank = $this->bank($seller);
        $withdrawal = Withdrawal::create(['user_id' => $seller->id, 'transaction_id' => $tx->id, 'payment_id' => $payment->id, 'bank_id' => $bank->id, 'narration' => 'Payout']);
        $this->signIn(User::factory()->create(['is_admin' => true]));

        $this->postJson('/api/transfer-to-bank', [
            'id' => $withdrawal->id,
            'amount' => 99999999,
            'account_number' => '9999999999',
            'account_bank' => '000',
        ])->assertOk();

        Http::assertSent(fn ($request) => $request['amount'] == 1800
            && $request['account_number'] === '0123456789'
            && $request['account_bank'] === '058');
        $this->assertTrue((bool) $withdrawal->fresh()->status);
        $this->assertSame(4242, (int) $withdrawal->fresh()->f_withdrawal_id);
        $this->assertTrue((bool) $payment->fresh()->withdrawn);
    }

    public function testATransferCannotBeSentTwice()
    {
        Http::fake(['*' => Http::response(['status' => 'success', 'data' => ['id' => 1]])]);
        [$seller, , $tx, $payment] = $this->paidSale();
        $withdrawal = Withdrawal::create(['user_id' => $seller->id, 'transaction_id' => $tx->id, 'payment_id' => $payment->id, 'bank_id' => $this->bank($seller)->id]);
        $this->signIn(User::factory()->create(['is_admin' => true]));

        $this->postJson('/api/transfer-to-bank', ['id' => $withdrawal->id])->assertOk();
        $this->postJson('/api/transfer-to-bank', ['id' => $withdrawal->id])->assertStatus(409)->assertJsonPath('code', 'payout_already_paid');

        Http::assertSentCount(1);
    }

    public function testFailedProviderTransferLeavesTheRequestPending()
    {
        Http::fake(['*' => Http::response(['status' => 'error', 'message' => 'Insufficient balance'])]);
        [$seller, , $tx, $payment] = $this->paidSale();
        $withdrawal = Withdrawal::create(['user_id' => $seller->id, 'transaction_id' => $tx->id, 'payment_id' => $payment->id, 'bank_id' => $this->bank($seller)->id]);
        $this->signIn(User::factory()->create(['is_admin' => true]));

        $this->postJson('/api/transfer-to-bank', ['id' => $withdrawal->id])
            ->assertStatus(502)
            ->assertJsonPath('message', 'Insufficient balance');

        $this->assertFalse((bool) $withdrawal->fresh()->status);
        $this->assertFalse((bool) $payment->fresh()->withdrawn);
    }

    // ---------------------------------------------------------------- refunds

    public function testBuyerCanRefundACancelledTransaction()
    {
        [, $buyer, $tx, $payment] = $this->paidSale(['status' => 2]);
        $bank = $this->bank($buyer);
        $this->signIn($buyer);

        $this->postJson('/api/request-refund', $this->withdrawalPayload($tx, $payment, $bank))->assertOk();

        $this->assertDatabaseHas('refunds', ['payment_id' => $payment->id, 'user_id' => $buyer->id]);
    }

    public function testRefundIsRefusedForACompletedTransaction()
    {
        [, $buyer, $tx, $payment] = $this->paidSale(['status' => 1]);
        $this->signIn($buyer);

        $this->postJson('/api/request-refund', $this->withdrawalPayload($tx, $payment, $this->bank($buyer)))
            ->assertStatus(422);
    }

    public function testSellerCannotRequestTheBuyersRefund()
    {
        [$seller, , $tx, $payment] = $this->paidSale(['status' => 2]);
        $this->signIn($seller);

        $this->postJson('/api/request-refund', $this->withdrawalPayload($tx, $payment, $this->bank($seller)))
            ->assertStatus(403);
    }

    public function testAdminRefundTransfersTheBuyersFullPayment()
    {
        Http::fake(['*' => Http::response(['status' => 'success', 'data' => ['id' => 77]])]);
        [, $buyer, $tx, $payment] = $this->paidSale(['status' => 2]);
        $refund = Refund::create(['user_id' => $buyer->id, 'transaction_id' => $tx->id, 'payment_id' => $payment->id, 'bank_id' => $this->bank($buyer)->id]);
        $this->signIn(User::factory()->create(['is_admin' => true]));

        $this->postJson('/api/transfer-to-buyer-bank', ['id' => $refund->id])->assertOk();

        Http::assertSent(fn ($request) => $request['amount'] == 2000);
        $this->assertTrue((bool) $payment->fresh()->refund);
    }

    public function testRefundTransferIsAdminOnly()
    {
        [, $buyer, $tx, $payment] = $this->paidSale(['status' => 2]);
        $refund = Refund::create(['user_id' => $buyer->id, 'transaction_id' => $tx->id, 'payment_id' => $payment->id, 'bank_id' => $this->bank($buyer)->id]);
        $this->signIn($buyer);

        $this->postJson('/api/transfer-to-buyer-bank', ['id' => $refund->id])->assertStatus(403);
    }

    // ------------------------------------------------------ referral bonuses

    private function bonus(User $user, $amount, array $attributes = []): Referer
    {
        return Referer::create(array_merge(['user_id' => $user->id, 'amount' => $amount], $attributes))
            ->forceFill(array_merge(['transaction_status' => true], $attributes));
    }

    public function testReferralWithdrawalNeedsAtLeastThreeThousand()
    {
        $user = User::factory()->create();
        $this->bank($user);
        $this->bonus($user, 2000)->save();
        $this->signIn($user);

        $this->postJson('/api/request-referral-bonus-withdrawal')
            ->assertStatus(400)
            ->assertJsonPath('code', 'payout_below_minimum');
    }

    public function testReferralWithdrawalCollectsEligibleBonusesOnly()
    {
        $user = User::factory()->create();
        $bank = $this->bank($user);
        $this->bonus($user, 2000)->save();
        $this->bonus($user, 1500)->save();
        $pending = $this->bonus($user, 9000, ['transaction_status' => false]);
        $pending->save();
        $this->signIn($user);

        $this->postJson('/api/request-referral-bonus-withdrawal')->assertOk();

        $withdrawal = ReferralWidrawal::first();
        $this->assertEquals(3500, $withdrawal->amount);
        $this->assertSame($bank->id, $withdrawal->bank_id);
        $this->assertSame(2, Referer::where('withdrawal_status', true)->count());
        $this->assertFalse((bool) $pending->fresh()->withdrawal_status);
    }

    public function testReferralWithdrawalNeedsABankAccount()
    {
        $user = User::factory()->create();
        $this->bonus($user, 5000)->save();
        $this->signIn($user);

        $this->postJson('/api/request-referral-bonus-withdrawal')->assertStatus(422)->assertJsonPath('code', 'payout_no_bank');
        $this->assertSame(0, Referer::where('withdrawal_status', true)->count());
    }

    public function testAdminReferralTransferPaysTheStoredAmount()
    {
        Http::fake(['*' => Http::response(['status' => 'success', 'data' => ['id' => 5]])]);
        $user = User::factory()->create();
        $bank = $this->bank($user);
        $bonus = $this->bonus($user, 4000, ['withdrawal_status' => true]);
        $bonus->save();
        $withdrawal = ReferralWidrawal::create(['user_id' => $user->id, 'bank_id' => $bank->id, 'amount' => 4000]);
        $other = $this->bonus(User::factory()->create(), 100, ['withdrawal_status' => true]);
        $other->save();
        $this->signIn(User::factory()->create(['is_admin' => true]));

        $this->postJson('/api/transfer-referral-bonus-to-bank', ['id' => $withdrawal->id])->assertOk();

        Http::assertSent(fn ($request) => $request['amount'] == 4000);
        $this->assertTrue((bool) $bonus->fresh()->transfer_status);
        $this->assertFalse((bool) $other->fresh()->transfer_status);
    }
}
