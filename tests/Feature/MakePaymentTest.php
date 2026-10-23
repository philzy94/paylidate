<?php

namespace Tests\Feature;

use App\Payment;
use App\Product;
use App\Transaction;
use App\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Passport\Passport;
use Tests\TestCase;

class MakePaymentTest extends TestCase
{
    use RefreshDatabase;

    private function transactionFor(Product $product, int $quantity): Transaction
    {
        return Transaction::create([
            'product_id' => $product->id,
            'quantity' => $quantity,
        ]);
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
        $product = Product::factory()->create(['price' => 1000, 'quantity' => 10, 'referral_amount' => 0]);
        $transaction = $this->transactionFor($product, 3);
        Passport::actingAs($buyer, [], 'api');

        $this->postJson('/api/make-payment', [
            'payment_id' => 'FLW-1',
            'payment_ref' => 'ref-1',
            'transaction_id' => $transaction->id,
        ])->assertJson(['status' => 'success']);

        $this->assertDatabaseHas('payments', [
            'payment_id' => 'FLW-1',
            'user_id' => $buyer->id,
            'verified' => true,
        ]);
        $this->assertSame(7, $product->fresh()->quantity);
        $this->assertEquals(3000, $transaction->fresh()->amount);
    }

    public function testMakePaymentRejectsAlreadyRecordedPayment()
    {
        $product = Product::factory()->create(['quantity' => 10, 'referral_amount' => 0]);
        $transaction = $this->transactionFor($product, 1);
        Payment::factory()->create(['payment_id' => 'FLW-2']);
        Passport::actingAs(User::factory()->create(), [], 'api');

        $this->postJson('/api/make-payment', [
            'payment_id' => 'FLW-2',
            'transaction_id' => $transaction->id,
        ])->assertJson(['status' => 'error', 'message' => 'This Payment has been verified']);

        $this->assertSame(10, $product->fresh()->quantity);
    }
}
