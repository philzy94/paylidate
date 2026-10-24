<?php

namespace Tests\Unit;

use App\Product;
use App\Transaction;
use App\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ModelRelationsTest extends TestCase
{
    use RefreshDatabase;

    public function testTransactionRelationsResolveWithoutErrors()
    {
        $product = Product::factory()->create();
        $transaction = Transaction::create(['user_id' => User::factory()->create()->id, 'product_id' => $product->id, 'quantity' => 1]);

        $transaction->load('product', 'payment', 'secondary_user', 'referral', 'withdrawal', 'refund', 'fulfilment', 'dispute', 'dispute_chat');

        $this->assertTrue($transaction->product->is($product));
        $this->assertNull($transaction->withdrawal);
        $this->assertNull($transaction->refund);
    }

    public function testProductBelongsToItsOwnerAndHasTransactions()
    {
        $product = Product::factory()->create();
        Transaction::create(['product_id' => $product->id, 'quantity' => 1]);

        $this->assertInstanceOf(User::class, $product->user);
        $this->assertCount(1, $product->transaction);
    }

    public function testEmailIsNormalisedToLowercase()
    {
        $user = User::factory()->create(['email' => 'MiXeD@Example.TEST']);

        $this->assertSame('mixed@example.test', $user->fresh()->email);
    }

    public function testNeedsPasswordResetUsersCanBeClaimed()
    {
        $this->assertTrue(User::factory()->make(['needs_password_reset' => true])->canBeClaimed());
        $this->assertTrue(User::factory()->make(['active' => false])->canBeClaimed());
        $this->assertFalse(User::factory()->make(['active' => true, 'needs_password_reset' => false])->canBeClaimed());
    }
}
