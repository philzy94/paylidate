<?php

namespace Tests\Feature;

use App\Fulfillment;
use App\Product;
use App\Transaction;
use App\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class FulfilmentTest extends TestCase
{
    use RefreshDatabase;

    private function order(): array
    {
        $buyer = User::factory()->create(['name' => 'Buyer Bob']);
        $product = Product::factory()->create(['name' => 'Laptop', 'description' => 'Fast']);
        $transaction = Transaction::create(['user_id' => $buyer->id, 'product_id' => $product->id, 'quantity' => 1, 'description' => 'Black']);
        $fulfilment = Fulfillment::create([
            'user_id' => $buyer->id,
            'transaction_id' => $transaction->id,
            'transaction_ref' => 'PD_X',
            'code' => 54321,
        ]);
        $hash = base64_encode($buyer->id . ':' . $transaction->id);

        return [$transaction, $fulfilment, $hash];
    }

    public function testSellerSeesTheOrderDetails()
    {
        [, , $hash] = $this->order();

        $this->getJson("/api/fulfillment/{$hash}")
            ->assertOk()
            ->assertJsonPath('data.product_name', 'Laptop')
            ->assertJsonPath('data.buyers_s_d', 'Black')
            ->assertJsonPath('data.buyers_name.name', 'Buyer Bob');
    }

    public function testMalformedLinksAreNotFound()
    {
        $this->getJson('/api/fulfillment/not-base64!!')->assertStatus(404)->assertJsonPath('code', 'fulfilment_link_invalid');
        $this->getJson('/api/fulfillment/' . base64_encode('1'))->assertStatus(404);
        $this->getJson('/api/fulfillment/' . base64_encode('9:9'))->assertStatus(404);
    }

    public function testCorrectCodeCompletesTheTransaction()
    {
        [$transaction, $fulfilment, $hash] = $this->order();

        $this->postJson("/api/fulfillment/{$hash}", ['code' => 54321])->assertOk();

        $this->assertSame(Fulfillment::SUCCESSFUL, $fulfilment->fresh()->status);
        $this->assertSame(1, $transaction->fresh()->status);
    }

    public function testWrongCodeIsRejectedAndNothingChanges()
    {
        [$transaction, $fulfilment, $hash] = $this->order();

        $this->postJson("/api/fulfillment/{$hash}", ['code' => 11111])
            ->assertStatus(400)
            ->assertJsonPath('code', 'fulfilment_code_invalid');

        $this->assertSame(0, $transaction->fresh()->status);
    }

    public function testACodeCanOnlyBeUsedOnce()
    {
        [, , $hash] = $this->order();

        $this->postJson("/api/fulfillment/{$hash}", ['code' => 54321])->assertOk();
        $this->postJson("/api/fulfillment/{$hash}", ['code' => 54321])->assertStatus(409);
    }

    public function testCodeAttemptsAreRateLimited()
    {
        [, , $hash] = $this->order();

        for ($i = 0; $i < 5; $i++) {
            $this->postJson("/api/fulfillment/{$hash}", ['code' => 10000 + $i])->assertStatus(400);
        }

        $this->postJson("/api/fulfillment/{$hash}", ['code' => 54321])->assertStatus(429);
    }

    public function testCodeIsRequired()
    {
        [, , $hash] = $this->order();

        $this->postJson("/api/fulfillment/{$hash}", [])->assertStatus(422)->assertJsonValidationErrors('code');
    }
}
