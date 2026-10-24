<?php

namespace Tests\Feature;

use App\Mail\CreateProductMail;
use App\Mail\SellerAcceptTransactionMail;
use App\Product;
use App\Recommendation;
use App\Transaction;
use App\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Laravel\Passport\Passport;
use Tests\TestCase;

class ProductControllerTest extends TestCase
{
    use RefreshDatabase;

    private function signIn(User $user): User
    {
        Passport::actingAs($user, [], 'api');

        return $user;
    }

    private function payload(array $overrides = []): array
    {
        return array_merge([
            'name' => 'Laptop',
            'price' => 1000,
            'quantity' => 3,
            'type' => 'product',
            'transaction_type' => 'sell',
        ], $overrides);
    }

    public function testStoreCreatesASellListingOwnedByTheCaller()
    {
        Mail::fake();
        $user = $this->signIn(User::factory()->create());

        $this->postJson('/api/product', $this->payload(['referral_amount' => 100, 'user_id' => 999, 'slug' => 'hijack']))
            ->assertOk();

        $product = Product::first();
        $this->assertSame($user->id, $product->user_id);
        $this->assertNotSame('hijack', $product->slug);
        $this->assertEquals(100, $product->referral_amount);
        Mail::assertSent(CreateProductMail::class);
        Mail::assertNotSent(SellerAcceptTransactionMail::class);
    }

    public function testStoreValidatesInput()
    {
        $this->signIn(User::factory()->create());

        $this->postJson('/api/product', ['transaction_type' => 'swap'])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['name', 'price', 'quantity', 'type', 'transaction_type']);
    }

    public function testReferralMustBeLessThanThePrice()
    {
        $this->signIn(User::factory()->create());

        $this->postJson('/api/product', $this->payload(['referral_amount' => 1000]))
            ->assertStatus(422)
            ->assertJsonPath('code', 'product_referral_too_high');
    }

    public function testBuyListingInvitesAnExistingSeller()
    {
        Mail::fake();
        $buyer = $this->signIn(User::factory()->create());
        $seller = User::factory()->create(['email' => 'seller@example.test']);

        $this->postJson('/api/product', $this->payload(['transaction_type' => 'buy', 'seller_email' => 'seller@example.test', 'referral_amount' => 50]))
            ->assertOk();

        $this->assertEquals(0, Product::first()->referral_amount);
        $this->assertDatabaseHas('transactions', ['user_id' => $seller->id, 'quantity' => 3]);
        Mail::assertSent(SellerAcceptTransactionMail::class);
    }

    public function testBuyListingCreatesAClaimablePlaceholderAccountForAnUnknownSeller()
    {
        Mail::fake();
        $this->signIn(User::factory()->create());

        $this->postJson('/api/product', $this->payload(['transaction_type' => 'buy', 'seller_email' => 'new@example.test', 'is_admin' => true]))
            ->assertOk();

        $placeholder = User::where('email', 'new@example.test')->first();
        $this->assertFalse((bool) $placeholder->active);
        $this->assertTrue((bool) $placeholder->needs_password_reset);
        $this->assertFalse((bool) $placeholder->is_admin);
        $this->assertNotSame('defualt', $placeholder->password);
        $this->assertTrue($placeholder->canBeClaimed());
    }

    public function testBuyListingRequiresASellerEmail()
    {
        $this->signIn(User::factory()->create());

        $this->postJson('/api/product', $this->payload(['transaction_type' => 'buy']))
            ->assertStatus(422)
            ->assertJsonValidationErrors('seller_email');
    }

    public function testIndexListsOnlyMyProducts()
    {
        $me = $this->signIn(User::factory()->create());
        Product::factory()->create(['user_id' => $me->id]);
        Product::factory()->count(2)->create();

        $this->getJson('/api/product')->assertOk()->assertJsonCount(1, 'data');
    }

    public function testProductPageIsPublicBySlug()
    {
        $product = Product::factory()->create(['slug' => 'abc123']);

        $this->getJson('/api/product/abc123')->assertOk()->assertJsonPath('data.id', $product->id);
        $this->getJson('/api/product/missing')->assertStatus(404)->assertJsonPath('code', 'product_not_found');
    }

    public function testOwnerCanUpdateOnlyAllowedFields()
    {
        $owner = $this->signIn(User::factory()->create());
        $product = Product::factory()->create(['user_id' => $owner->id, 'transaction_type' => 'sell', 'price' => 500]);

        $this->putJson("/api/product/{$product->id}", $this->payload(['price' => 900, 'user_id' => 777, 'slug' => 'x']))->assertOk();

        $product->refresh();
        $this->assertEquals(900, $product->price);
        $this->assertSame($owner->id, $product->user_id);
        $this->assertNotSame('x', $product->slug);
    }

    public function testOthersCannotUpdateOrDeleteAProduct()
    {
        $product = Product::factory()->create(['transaction_type' => 'sell']);
        $this->signIn(User::factory()->create());

        $this->putJson("/api/product/{$product->id}", $this->payload())->assertStatus(403);
        $this->deleteJson("/api/product/{$product->id}")->assertStatus(403);
        $this->postJson("/api/product/status/{$product->id}", ['status' => 0])->assertStatus(403);
        $this->postJson("/api/product/status/delivered/{$product->id}")->assertStatus(403);

        $this->assertDatabaseHas('products', ['id' => $product->id]);
    }

    public function testBuyListingsCannotBeEdited()
    {
        $owner = $this->signIn(User::factory()->create());
        $product = Product::factory()->create(['user_id' => $owner->id, 'transaction_type' => 'buy']);

        $this->putJson("/api/product/{$product->id}", $this->payload())->assertStatus(403)->assertJsonPath('code', 'product_buy_listing');
    }

    public function testDeleteIsBlockedOnceTheProductHasTransactions()
    {
        $owner = $this->signIn(User::factory()->create());
        $free = Product::factory()->create(['user_id' => $owner->id]);
        $used = Product::factory()->create(['user_id' => $owner->id]);
        Transaction::create(['product_id' => $used->id, 'quantity' => 1]);

        $this->deleteJson("/api/product/{$free->id}")->assertOk();
        $this->deleteJson("/api/product/{$used->id}")->assertStatus(409)->assertJsonPath('code', 'product_has_transactions');

        $this->assertDatabaseMissing('products', ['id' => $free->id]);
    }

    public function testOwnerCanToggleAvailability()
    {
        $owner = $this->signIn(User::factory()->create());
        $product = Product::factory()->create(['user_id' => $owner->id, 'product_status' => true]);

        $this->postJson("/api/product/status/{$product->id}", ['status' => 0])->assertOk();

        $this->assertFalse((bool) $product->fresh()->product_status);
    }

    public function testSellerOfASellListingMarksItDelivered()
    {
        $seller = $this->signIn(User::factory()->create());
        $product = Product::factory()->create(['user_id' => $seller->id, 'transaction_type' => 'sell']);

        $this->postJson("/api/product/status/delivered/{$product->id}")->assertOk();

        $this->assertSame(2, (int) $product->fresh()->delivery_status);
    }

    public function testInvitedSellerOfABuyListingMarksItDelivered()
    {
        $seller = User::factory()->create();
        $product = Product::factory()->create(['transaction_type' => 'buy']);
        Transaction::create(['product_id' => $product->id, 'user_id' => $seller->id, 'quantity' => 1]);
        $this->signIn($seller);

        $this->postJson("/api/product/status/delivered/{$product->id}")->assertOk();

        $this->assertSame(2, (int) $product->fresh()->delivery_status);
    }

    public function testCatalogShowsOnlyAvailableProductsOfAUser()
    {
        $owner = User::factory()->create();
        Product::factory()->create(['user_id' => $owner->id, 'product_status' => true]);
        Product::factory()->create(['user_id' => $owner->id, 'product_status' => false]);

        $this->getJson("/api/catalog/{$owner->id}")->assertOk()->assertJsonCount(1, 'data');
    }

    public function testRecommendationsAreIdempotentAndRemovable()
    {
        $me = $this->signIn(User::factory()->create());
        $product = Product::factory()->create();

        $this->postJson("/api/product/{$product->id}/recommend")->assertOk();
        $this->postJson("/api/product/{$product->id}/recommend")->assertOk();
        $this->assertSame(1, Recommendation::where('user_id', $me->id)->count());

        $this->deleteJson("/api/product/{$product->id}/recommend")->assertOk();
        $this->assertSame(0, Recommendation::count());
        $this->postJson('/api/product/99999/recommend')->assertStatus(404);
    }
}
