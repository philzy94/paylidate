<?php

namespace Tests\Feature;

use App\Payment;
use App\Product;
use App\Referer;
use App\Transaction;
use App\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Laravel\Passport\Passport;
use Tests\TestCase;

class MakePaymentTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config(['services.flutterwave' => [
            'public_key' => 'pub',
            'secret_key' => 'FLWSECK-abcdefabcdefabcdef',
            'base_url' => 'https://flw.test',
            'rave_base_url' => 'https://rave.test',
        ]]);
    }

    private function fakeProvider(array $data): void
    {
        Http::fake(['rave.test/*' => Http::response(['status' => 'success', 'data' => $data])]);
    }

    private function paid(float $amount = 3000, string $status = 'successful', string $currency = 'NGN'): void
    {
        $this->fakeProvider(['status' => $status, 'amount' => $amount, 'currency' => $currency]);
    }

    /** A "sell" listing with a transaction opened by $buyer. */
    private function order(User $buyer, array $product = [], int $quantity = 3): array
    {
        $product = Product::factory()->create(array_merge([
            'transaction_type' => 'sell',
            'price' => 1000,
            'quantity' => 10,
            'referral_amount' => 0,
        ], $product));
        $transaction = Transaction::create(['user_id' => $buyer->id, 'product_id' => $product->id, 'quantity' => $quantity]);

        return [$product, $transaction];
    }

    private function pay(Transaction $transaction, array $overrides = [])
    {
        return $this->postJson('/api/make-payment', array_merge([
            'payment_id' => 'FLW-1',
            'payment_ref' => 'ref-1',
            'transaction_id' => $transaction->id,
        ], $overrides));
    }

    public function testMakePaymentRequiresAuthentication()
    {
        $this->postJson('/api/make-payment', [])->assertStatus(401);
    }

    public function testMakePaymentValidatesInput()
    {
        Passport::actingAs(User::factory()->create(), [], 'api');

        $this->postJson('/api/make-payment', [])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['payment_id', 'transaction_id']);
    }

    public function testMakePaymentRecordsPaymentAndReducesStock()
    {
        $buyer = User::factory()->create();
        [$product, $transaction] = $this->order($buyer);
        $this->paid(3000);
        Passport::actingAs($buyer, [], 'api');

        $this->pay($transaction)->assertJson(['status' => 'success']);

        $this->assertDatabaseHas('payments', ['payment_id' => 'FLW-1', 'user_id' => $buyer->id, 'verified' => true]);
        $this->assertSame(7, $product->fresh()->quantity);
        $this->assertEquals(3000, $transaction->fresh()->amount);
        Http::assertSent(fn ($request) => $request['txref'] === 'ref-1');
    }

    public function testMakePaymentSplitsTheReferralAmount()
    {
        $buyer = User::factory()->create();
        $referral = Referer::create(['user_id' => User::factory()->create()->id, 'amount' => 0]);
        [, $transaction] = $this->order($buyer, ['referral_amount' => 100], 2);
        $transaction->update(['referer_id' => $referral->id]);
        $this->paid(2000);
        Passport::actingAs($buyer, [], 'api');

        $this->pay($transaction)->assertOk();

        $this->assertEquals(1800, $transaction->fresh()->amount);
        $this->assertEquals(200, $referral->fresh()->amount);
    }

    public function testAnUnconfirmedPaymentIsNotRecorded()
    {
        $buyer = User::factory()->create();
        [$product, $transaction] = $this->order($buyer);
        $this->paid(3000, 'failed');
        Passport::actingAs($buyer, [], 'api');

        $this->pay($transaction)->assertStatus(422)->assertJson(['code' => 'payment_verification_failed']);

        $this->assertSame(0, Payment::count());
        $this->assertSame(10, $product->fresh()->quantity);
    }

    public function testAnUnderpaymentIsNotRecorded()
    {
        $buyer = User::factory()->create();
        [, $transaction] = $this->order($buyer);
        $this->paid(1);
        Passport::actingAs($buyer, [], 'api');

        $this->pay($transaction)->assertStatus(422);

        $this->assertSame(0, Payment::count());
    }

    public function testAForeignCurrencyPaymentIsNotAccepted()
    {
        $buyer = User::factory()->create();
        [, $transaction] = $this->order($buyer);
        $this->paid(3000, 'successful', 'USD');
        Passport::actingAs($buyer, [], 'api');

        $this->pay($transaction)->assertStatus(422);
    }

    public function testOnlyTheBuyerCanPayForATransaction()
    {
        $buyer = User::factory()->create();
        [, $transaction] = $this->order($buyer);
        $this->paid(3000);
        Passport::actingAs(User::factory()->create(), [], 'api');

        $this->pay($transaction)->assertStatus(403)->assertJson(['code' => 'payment_not_buyer']);

        $this->assertSame(0, Payment::count());
    }

    public function testOnBuyListingsTheProductOwnerIsTheBuyer()
    {
        $owner = User::factory()->create();
        $seller = User::factory()->create();
        $product = Product::factory()->create(['user_id' => $owner->id, 'transaction_type' => 'buy', 'price' => 500, 'quantity' => 4]);
        $transaction = Transaction::create(['user_id' => $seller->id, 'product_id' => $product->id, 'quantity' => 4]);
        $this->paid(2000);

        Passport::actingAs($seller, [], 'api');
        $this->pay($transaction)->assertStatus(403);

        Passport::actingAs($owner, [], 'api');
        $this->pay($transaction)->assertOk();
    }

    public function testMakePaymentRejectsAlreadyRecordedPayment()
    {
        $buyer = User::factory()->create();
        [$product, $transaction] = $this->order($buyer);
        Payment::factory()->create(['payment_id' => 'FLW-1']);
        $this->paid(3000);
        Passport::actingAs($buyer, [], 'api');

        $this->pay($transaction)
            ->assertStatus(409)
            ->assertJson([
                'status' => 'error',
                'code' => 'payment_already_verified',
                'message' => 'This Payment has been verified',
            ]);

        $this->assertSame(10, $product->fresh()->quantity);
    }

    public function testATransactionCanOnlyBePaidOnce()
    {
        $buyer = User::factory()->create();
        [$product, $transaction] = $this->order($buyer);
        $this->paid(3000);
        Passport::actingAs($buyer, [], 'api');

        $this->pay($transaction)->assertOk();
        $this->pay($transaction, ['payment_id' => 'FLW-OTHER', 'payment_ref' => 'ref-other'])->assertStatus(409);

        $this->assertSame(1, Payment::where('transaction_id', $transaction->id)->count());
        $this->assertSame(7, $product->fresh()->quantity);
    }

    public function testMakePaymentFailsWhenProductIsMissing()
    {
        $buyer = User::factory()->create();
        $transaction = Transaction::create(['user_id' => $buyer->id, 'product_id' => 999999, 'quantity' => 1]);
        Passport::actingAs($buyer, [], 'api');

        $this->pay($transaction, ['payment_id' => 'FLW-3'])
            ->assertStatus(422)
            ->assertJson(['code' => 'payment_verification_failed']);

        $this->assertDatabaseMissing('payments', ['payment_id' => 'FLW-3']);
    }
}
