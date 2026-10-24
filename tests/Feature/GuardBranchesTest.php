<?php

namespace Tests\Feature;

use App\Apikey;
use App\Instandpay;
use App\Product;
use App\Services\SmsService;
use App\Transaction;
use App\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Mail;
use Laravel\Passport\Passport;
use Tests\TestCase;

/**
 * The "not found", "not yours" and "invalid input" branches of the services,
 * exercised through the HTTP API.
 */
class GuardBranchesTest extends TestCase
{
    use RefreshDatabase;

    private function signIn(User $user): User
    {
        Passport::actingAs($user, [], 'api');

        return $user;
    }

    private function product(array $attributes = []): Product
    {
        return Product::factory()->create(array_merge([
            'quantity' => 10,
            'price' => 500,
            'referral_amount' => 0,
            'transaction_type' => 'sell',
        ], $attributes));
    }

    private function transaction(Product $product, User $initiator, array $attributes = []): Transaction
    {
        return Transaction::create(array_merge([
            'user_id' => $initiator->id,
            'product_id' => $product->id,
            'quantity' => 1,
            'transaction_ref' => 'PD_GUARD_' . uniqid(),
        ], $attributes));
    }

    // ----------------------------------------------------------- transactions

    public function testCreatingATransactionForAnUnknownProductIsNotFound()
    {
        $this->signIn(User::factory()->create());

        $this->postJson('/api/transaction', ['product_id' => 999999, 'quantity' => 1])
            ->assertStatus(404)
            ->assertJsonPath('code', 'transaction_not_found');
    }

    public function testAnUnknownTransactionIdIsNotFound()
    {
        $this->signIn(User::factory()->create());

        $this->postJson('/api/transaction/accept/999999')
            ->assertStatus(404)
            ->assertJsonPath('code', 'transaction_not_found');
    }

    public function testOnlyTheBuyerCanReportResolveOrCancel()
    {
        Mail::fake();
        $product = $this->product();
        $transaction = $this->transaction($product, User::factory()->create(), ['status' => 3]);
        $this->signIn($product->user);

        $this->postJson("/api/transaction/report-transaction/{$transaction->id}", [
            'sellerEmail' => $product->user->email,
            'report' => 'Not delivered',
        ])->assertStatus(403)->assertJsonPath('code', 'transaction_not_allowed');
        $this->postJson("/api/transaction/reslove-report/{$transaction->id}/{$product->user->email}")
            ->assertStatus(403)
            ->assertJsonPath('code', 'transaction_not_allowed');
        $this->postJson("/api/transaction/cancel/{$transaction->id}")
            ->assertStatus(403)
            ->assertJsonPath('code', 'transaction_not_allowed');

        $this->assertSame(3, $transaction->fresh()->status);
    }

    public function testTheSellerIsTheInitiatorOnABuyListing()
    {
        $initiator = User::factory()->create();
        $owner = User::factory()->create();
        $product = $this->product(['user_id' => $owner->id, 'transaction_type' => 'buy']);
        $transaction = $this->transaction($product, $initiator);

        $this->getJson("/api/transaction/{$transaction->transaction_ref}")
            ->assertOk()
            ->assertJsonPath('data.seller_email', $initiator->email)
            ->assertJsonPath('data.product_initiator.id', $owner->id);
    }

    // -------------------------------------------------------------- products

    public function testPublicProductLookupFindsAProductBySlug()
    {
        $product = $this->product();

        $this->getJson("/api/product/{$product->slug}")->assertOk()->assertJsonPath('data.id', $product->id);
    }

    public function testBuyListingsCannotBeEditedAndReferralsMustStayBelowThePrice()
    {
        $owner = $this->signIn(User::factory()->create());
        $buy = $this->product(['user_id' => $owner->id, 'transaction_type' => 'buy']);
        $sell = $this->product(['user_id' => $owner->id]);
        $payload = ['name' => 'Laptop', 'price' => 1000, 'quantity' => 3, 'type' => 'product'];

        $this->putJson("/api/product/{$buy->id}", $payload)
            ->assertStatus(403)
            ->assertJsonPath('code', 'product_buy_listing');
        $this->putJson("/api/product/{$sell->id}", $payload + ['referral_amount' => 1000])
            ->assertStatus(422)
            ->assertJsonPath('code', 'product_referral_too_high');
    }

    public function testMarkingAnUnknownProductDeliveredIsNotFound()
    {
        $this->signIn(User::factory()->create());

        $this->postJson('/api/product/status/delivered/999999')->assertStatus(404);
    }

    public function testRecommendingAnUnknownProductIsNotFound()
    {
        $this->signIn(User::factory()->create());

        $this->postJson('/api/product/999999/recommend')->assertStatus(404);
    }

    public function testEditingAnUnknownProductIsNotFound()
    {
        $this->signIn(User::factory()->create());

        $this->putJson('/api/product/999999', ['name' => 'x', 'price' => 1, 'quantity' => 1, 'type' => 'product'])
            ->assertStatus(404);
    }

    // ------------------------------------------------------------ instant pay

    private function instantTransfer(User $receiver, array $attributes = []): Instandpay
    {
        $sender = User::factory()->create();

        return Instandpay::create(array_merge([
            'user_id' => $sender->id,
            'receiver_id' => $receiver->id,
            'receiver_name' => $receiver->name,
            'receiver_number' => $receiver->phone,
            'sender_email' => $sender->email,
            'sender_name' => $sender->name,
            'amount' => 5000,
            'payment_ref' => uniqid('ref'),
            'link_token' => 'PD_GUARD_TOKEN',
            'tracking_id' => '123',
            'withdrawal_pin' => Hash::make('654321'),
        ], $attributes));
    }

    public function testYouCannotInstantPayYourself()
    {
        $this->app->instance(SmsService::class, \Mockery::mock(SmsService::class));
        $this->signIn(User::factory()->create(['phone' => '08011111111']));

        $this->postJson('/api/instant-pay/tranfer', ['receiver_number' => '08011111111', 'payment_ref' => 'FLW-1'])
            ->assertStatus(400)
            ->assertJsonPath('code', 'instantpay_to_self');
    }

    public function testClaimingAnUnknownLinkIsNotFound()
    {
        $this->signIn(User::factory()->create());

        $this->postJson('/api/instant-pay/verify', [
            'withdrawal_pin' => 123456, 'link_token' => 'PD_NOPE', 'bank_code' => '058', 'account_number' => '0123456789',
        ])->assertStatus(404)->assertJsonPath('code', 'instantpay_not_found');
    }

    public function testClaimingAnAlreadyWithdrawnTransferIsRefused()
    {
        $receiver = $this->signIn(User::factory()->create(['phone' => '08022222222']));
        $this->instantTransfer($receiver, ['status' => true]);

        $this->postJson('/api/instant-pay/verify', [
            'withdrawal_pin' => 654321, 'link_token' => 'PD_GUARD_TOKEN', 'bank_code' => '058', 'account_number' => '0123456789',
        ])->assertStatus(409)->assertJsonPath('code', 'instantpay_withdrawn');
    }

    public function testWithdrawingAnUnknownTransferIsNotFound()
    {
        $this->signIn(User::factory()->create());

        $this->postJson('/api/instant-pay/withdraw', ['id' => 999999])
            ->assertStatus(404)
            ->assertJsonPath('code', 'instantpay_not_found');
    }

    // ------------------------------------------------------------- partner API

    public function testAKnownPartnerKeyReachesThePingEndpoint()
    {
        (new Apikey())->forceFill(['API_key' => 'partner-key-1'])->save();

        $this->getJson('/api/v1/ping', ['Authorization' => 'Bearer partner-key-1'])
            ->assertOk()
            ->assertJsonPath('message', 'pong');
    }
}
