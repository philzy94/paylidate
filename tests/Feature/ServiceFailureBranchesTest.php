<?php

namespace Tests\Feature;

use App\Exceptions\WalletException;
use App\Services\FlutterwaveService;
use App\Services\WalletService;
use App\User;
use App\Wallet;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use Laravel\Passport\Passport;
use Tests\TestCase;

class ServiceFailureBranchesTest extends TestCase
{
    use RefreshDatabase;

    public function testResolvingAnUnknownDisputeIsNotFound()
    {
        Passport::actingAs(User::factory()->create(), [], 'api');

        $this->postJson('/api/resolve-dispute', ['id' => 999999])
            ->assertStatus(404);
    }

    public function testAnUnknownFulfilmentLinkIsInvalid()
    {
        $hash = base64_encode('999:999');

        $this->postJson("/api/fulfillment/{$hash}", ['code' => '123456'])
            ->assertStatus(404)
            ->assertJsonPath('code', 'fulfilment_link_invalid');
    }

    public function testSpendingWithoutAWalletIsRefused()
    {
        $this->expectException(WalletException::class);

        app(WalletService::class)->spend(User::factory()->create(), 100, 'Coffee');
    }

    public function testSpendingLessThanOneNairaIsRefused()
    {
        $user = User::factory()->create();
        Wallet::create([
            'user_id' => $user->id, 'order_ref' => 'URF_1', 'tx_ref' => 'PD_1', 'account_number' => '1', 'balance' => 500, 'bonus' => 0,
        ]);

        try {
            app(WalletService::class)->spend($user, 0.5, 'Tiny');
            $this->fail('Expected a WalletException');
        } catch (WalletException $e) {
            $this->assertSame(400, $e->httpStatus());
        }

        $this->assertEquals(500, $user->wallet->fresh()->balance);
    }

    public function testATransferThatCannotReachFlutterwaveReturnsAGenericError()
    {
        config(['services.flutterwave.base_url' => 'https://flw.test']);
        Http::fake(fn () => throw new ConnectionException('timeout'));

        $result = (new FlutterwaveService())->transfer_to_bank([
            'account_bank' => '058', 'account_number' => '0123456789', 'amount' => 100, 'narration' => 'Payout',
        ]);

        $this->assertSame(['status' => 'error', 'message' => 'An error occured. Please try again'], $result);
    }
}
