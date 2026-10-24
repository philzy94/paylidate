<?php

namespace Tests\Feature;

use App\Dispute;
use App\Transaction;
use App\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Passport\Passport;
use Tests\TestCase;

class AdminAccessTest extends TestCase
{
    use RefreshDatabase;

    public function testGuestsCannotReachAdminRoutes()
    {
        $this->getJson('/api/admin/users')->assertStatus(401);
        $this->getJson('/api/admin/dashboard')->assertStatus(401);
    }

    public function testNormalUsersAreForbiddenFromAdminRoutes()
    {
        Passport::actingAs(User::factory()->create(['is_admin' => false]), [], 'api');

        $this->getJson('/api/admin/users')->assertStatus(403)->assertJsonPath('code', 'forbidden');
        $this->getJson('/api/admin/dashboard')->assertStatus(403);
        $this->getJson('/api/admin/users/2020-01-01/2030-01-01')->assertStatus(403);
    }

    public function testAdminsCanListUsers()
    {
        User::factory()->count(2)->create();
        Passport::actingAs(User::factory()->create(['is_admin' => true]), [], 'api');

        $this->getJson('/api/admin/users')->assertOk()->assertJsonCount(3, 'data');
    }

    public function testAdminsCanFilterUsersByDate()
    {
        $old = User::factory()->create();
        $old->forceFill(['created_at' => '2020-06-01 00:00:00'])->save();
        Passport::actingAs(User::factory()->create(['is_admin' => true]), [], 'api');

        $this->getJson('/api/admin/users/2020-01-01/2020-12-31')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.id', $old->id);
    }

    public function testDashboardSummarisesTheBusiness()
    {
        $admin = User::factory()->create(['is_admin' => true]);
        $buyer = User::factory()->create();
        $product = \App\Product::factory()->create();
        $transaction = Transaction::create(['user_id' => $buyer->id, 'product_id' => $product->id, 'quantity' => 1, 'status' => 1, 'amount' => 700]);
        Dispute::create(['user_id' => $buyer->id, 'transaction_id' => $transaction->id, 'subject' => 's', 'dispute' => 'd']);
        Passport::actingAs($admin, [], 'api');

        $response = $this->getJson('/api/admin/dashboard')->assertOk();

        $this->assertSame(3, $response->json('data.Total_registered_users'));
        $this->assertSame(1, $response->json('data.Total_Transactions_completed'));
        $this->assertEquals(700, $response->json('data.Total_Transactions_completed_amount'));
        $this->assertSame(1, $response->json('data.Total_Dispute'));
        $this->assertSame($buyer->name, $response->json('data.list_Of_Disputes.0.receiver_name'));
    }

    public function testThePublicUserDumpEndpointIsGone()
    {
        $this->getJson('/api/get-users')->assertStatus(404);
        $this->getJson('/api/get-users/1')->assertStatus(404);
        $this->getJson('/api/instant-get')->assertStatus(404);
    }

    public function testDemoFulfilmentEndpointsAreGone()
    {
        $this->getJson('/api/fulfillment/abcdef')->assertStatus(404);
        $this->postJson('/api/fulfillment/abcdef', ['code' => 1234])->assertStatus(404);
    }

    public function testThirdPartyApiRequiresAKnownKey()
    {
        $this->getJson('/api/v1/ping')->assertStatus(401);
        $this->getJson('/api/v1/ping', ['Authorization' => 'Bearer nope'])->assertStatus(403);
    }
}
