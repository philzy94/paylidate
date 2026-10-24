<?php

namespace Tests\Feature;

use App\Mail\CreateTransactionMail;
use App\Mail\ReportTransaction;
use App\Product;
use App\Referer;
use App\Transaction;
use App\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Laravel\Passport\Passport;
use Tests\TestCase;

class TransactionControllerTest extends TestCase
{
    use RefreshDatabase;

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
            'quantity' => 2,
            'transaction_ref' => 'PD_TEST_' . uniqid(),
            'accept_transaction' => null,
        ], $attributes));
    }

    private function signIn($user)
    {
        Passport::actingAs($user, [], 'api');
    }

    public function testStoreCreatesTransactionAndEmailsBothParties()
    {
        Mail::fake();
        $buyer = User::factory()->create();
        $product = $this->product();
        $this->signIn($buyer);

        $response = $this->postJson('/api/transaction', [
            'product_id' => $product->id,
            'quantity' => 3,
            'description' => 'Two day delivery',
        ]);

        $response->assertOk()->assertJsonPath('status', 'success');
        $this->assertDatabaseHas('transactions', [
            'user_id' => $buyer->id,
            'product_id' => $product->id,
            'quantity' => 3,
            'accept_transaction' => true,
        ]);
        $this->assertDatabaseHas('fulfillments', ['user_id' => $buyer->id]);
        Mail::assertSent(CreateTransactionMail::class, 2);
    }

    public function testStoreRejectsMoreThanAvailableQuantity()
    {
        Mail::fake();
        $this->signIn(User::factory()->create());
        $product = $this->product(['quantity' => 2]);

        $this->postJson('/api/transaction', ['product_id' => $product->id, 'quantity' => 5])
            ->assertStatus(422)
            ->assertJsonPath('code', 'quantity_unavailable');

        $this->assertDatabaseCount('transactions', 0);
    }

    public function testStoreRejectsUsingYourOwnReferralLink()
    {
        Mail::fake();
        $buyer = User::factory()->create();
        $this->signIn($buyer);
        $product = $this->product();

        $this->postJson('/api/transaction', [
            'product_id' => $product->id,
            'quantity' => 1,
            'referral_token' => $buyer->referral_token,
        ])->assertStatus(422)->assertJsonPath('code', 'own_referral');
    }

    public function testStoreCreatesReferralForAnotherUsersToken()
    {
        Mail::fake();
        $referrer = User::factory()->create();
        $this->signIn(User::factory()->create());
        $product = $this->product();

        $this->postJson('/api/transaction', [
            'product_id' => $product->id,
            'quantity' => 1,
            'referral_token' => $referrer->referral_token,
        ])->assertOk();

        $this->assertDatabaseHas('referers', ['user_id' => $referrer->id]);
        $this->assertNotNull(Transaction::first()->referer_id);
    }

    public function testStoreValidatesInput()
    {
        $this->signIn(User::factory()->create());

        $this->postJson('/api/transaction', [])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['product_id', 'quantity']);
    }

    public function testGetTransactionReturnsDetailsPublicly()
    {
        $initiator = User::factory()->create();
        $product = $this->product();
        $transaction = $this->transaction($product, $initiator, ['transaction_ref' => 'PD_ABC']);

        $this->getJson('/api/transaction/PD_ABC')
            ->assertOk()
            ->assertJsonPath('data.id', $transaction->id)
            ->assertJsonPath('data.product_initiator.id', $product->user_id);
    }

    public function testGetTransactionReturnsNotFound()
    {
        $this->getJson('/api/transaction/PD_MISSING')
            ->assertStatus(404)
            ->assertJsonPath('code', 'transaction_not_found');
    }

    public function testGetTransactionExpiresALapsedReport()
    {
        $initiator = User::factory()->create();
        $transaction = $this->transaction($this->product(), $initiator, [
            'transaction_ref' => 'PD_LAPSED',
            'status' => 3,
        ]);
        $transaction->forceFill(['transaction_reported_at' => now()->subHours(25)])->save();

        $this->getJson('/api/transaction/PD_LAPSED')->assertOk();

        $this->assertSame(4, $transaction->fresh()->status);
    }

    public function testAcceptAndDeclineByInitiator()
    {
        $initiator = User::factory()->create();
        $transaction = $this->transaction($this->product(), $initiator);
        $this->signIn($initiator);

        $this->postJson("/api/transaction/accept/{$transaction->id}")->assertOk();
        $this->assertEquals(1, $transaction->fresh()->accept_transaction);

        $this->postJson("/api/transaction/decline/{$transaction->id}")->assertOk();
        $this->assertEquals(0, $transaction->fresh()->accept_transaction);
    }

    public function testAcceptByAnotherUserIsForbidden()
    {
        $transaction = $this->transaction($this->product(), User::factory()->create());
        $this->signIn(User::factory()->create());

        $this->postJson("/api/transaction/accept/{$transaction->id}")
            ->assertStatus(403)
            ->assertJsonPath('code', 'transaction_not_allowed');

        $this->assertNull($transaction->fresh()->accept_transaction);
    }

    public function testConfirmByBuyerCompletesTransactionAndReferral()
    {
        $buyer = User::factory()->create();
        $referral = Referer::create(['user_id' => User::factory()->create()->id, 'amount' => 10]);
        $transaction = $this->transaction($this->product(), $buyer, ['referer_id' => $referral->id]);
        $this->signIn($buyer);

        $this->postJson("/api/transaction/confirm/{$transaction->id}")->assertOk();

        $this->assertSame(1, $transaction->fresh()->status);
        $this->assertEquals(1, $referral->fresh()->transaction_status);
    }

    public function testConfirmBySellerIsForbidden()
    {
        $buyer = User::factory()->create();
        $product = $this->product();
        $transaction = $this->transaction($product, $buyer);
        $this->signIn($product->user);

        $this->postJson("/api/transaction/confirm/{$transaction->id}")->assertStatus(403);
    }

    public function testCancelRestoresStock()
    {
        $buyer = User::factory()->create();
        $product = $this->product(['quantity' => 5]);
        $transaction = $this->transaction($product, $buyer, ['quantity' => 2]);
        $this->signIn($buyer);

        $this->postJson("/api/transaction/cancel/{$transaction->id}")->assertOk();

        $this->assertSame(2, $transaction->fresh()->status);
        $this->assertSame(7, $product->fresh()->quantity);
    }

    public function testReportMarksTransactionAndNotifiesSeller()
    {
        Mail::fake();
        config(['app.admin_emails' => ['staff@example.test']]);
        $buyer = User::factory()->create();
        $product = $this->product();
        $transaction = $this->transaction($product, $buyer);
        $this->signIn($buyer);

        $this->postJson("/api/transaction/report-transaction/{$transaction->id}", [
            'sellerEmail' => $product->user->email,
            'report' => 'Item never arrived',
        ])->assertOk();

        $this->assertSame(3, $transaction->fresh()->status);
        $this->assertNotNull($transaction->fresh()->transaction_reported_at);
        Mail::assertSent(ReportTransaction::class, 2);
    }

    public function testReportRequiresAValidEmailAndReason()
    {
        $buyer = User::factory()->create();
        $transaction = $this->transaction($this->product(), $buyer);
        $this->signIn($buyer);

        $this->postJson("/api/transaction/report-transaction/{$transaction->id}", ['sellerEmail' => 'nope', 'report' => 'x'])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['sellerEmail', 'report']);
    }

    public function testResolveReportReopensAsCancelled()
    {
        Mail::fake();
        $buyer = User::factory()->create();
        $product = $this->product();
        $transaction = $this->transaction($product, $buyer, ['status' => 3]);
        $this->signIn($buyer);

        $this->postJson("/api/transaction/reslove-report/{$transaction->id}/{$product->user->email}")->assertOk();

        $this->assertSame(2, $transaction->fresh()->status);
    }

    public function testIndexOnlyListsTransactionsTheUserTakesPartIn()
    {
        $mine = User::factory()->create();
        $product = $this->product();
        $own = $this->transaction($product, $mine);
        $this->transaction($product, User::factory()->create());
        $this->signIn($mine);

        $response = $this->getJson('/api/transaction')->assertOk();

        $this->assertCount(1, $response->json('data'));
        $this->assertSame($own->id, $response->json('data.0.id'));
    }

    public function testThirdPartyApiUsesTheSameService()
    {
        $this->assertSame(
            (new \ReflectionMethod(\App\Http\Controllers\Api\TransactionController::class, '__construct'))->getParameters()[0]->getType()->getName(),
            (new \ReflectionMethod(\App\Http\Controllers\ThirdPartyApi\TransactionController::class, '__construct'))->getParameters()[0]->getType()->getName()
        );
    }
}
