<?php

namespace Tests\Unit\Services;

use App\Exceptions\WalletException;
use App\Services\FlutterwaveService;
use App\Services\WalletService;
use App\User;
use App\Wallet;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class WalletServiceTest extends TestCase
{
    use RefreshDatabase;

    private function service(): WalletService
    {
        return new WalletService(new FlutterwaveService());
    }

    private function wallet(array $attributes = []): Wallet
    {
        return Wallet::create(array_merge([
            'user_id' => User::factory()->create()->id,
            'order_ref' => uniqid('URF_'),
            'tx_ref' => uniqid('PD_'),
            'account_number' => '7824822527',
            'balance' => 1000,
            'bonus' => 5000,
        ], $attributes));
    }

    public function testCreateWalletStoresVirtualAccountDetails()
    {
        config(['services.flutterwave.base_url' => 'https://flw.test']);
        Http::fake(['flw.test/*' => Http::response([
            'status' => 'success',
            'data' => ['order_ref' => 'URF_9', 'account_number' => '1234567890', 'bank_name' => 'WEMA BANK'],
        ])]);
        $user = User::factory()->create();

        $wallet = $this->service()->createWallet($user->id, $user->email, $user->name, '22222222222');

        $this->assertInstanceOf(Wallet::class, $wallet);
        $this->assertSame('1234567890', $wallet->account_number);
        $this->assertEquals(1000, $wallet->bonus);
    }

    public function testCreateWalletThrowsTheProviderErrorMessage()
    {
        config(['services.flutterwave.base_url' => 'https://flw.test']);
        Http::fake(['flw.test/*' => Http::response(['status' => 'error', 'message' => 'Invalid BVN'])]);

        try {
            $this->service()->createWallet(1, 'a@b.test', 'Ada', '1');
            $this->fail('Expected a WalletException');
        } catch (WalletException $e) {
            $this->assertSame('Invalid BVN', $e->getMessage());
            $this->assertSame('wallet_provider_error', $e->errorCode());
        }

        $this->assertSame(0, Wallet::count());
    }

    public function testCreateWalletReportsAnUnreachableProvider()
    {
        $flutterwave = \Mockery::mock(FlutterwaveService::class);
        $flutterwave->shouldReceive('createVirtualAccount')->andThrow(new \RuntimeException('timeout'));

        $this->expectException(WalletException::class);
        $this->expectExceptionMessage('An error occured. Please try again');

        (new WalletService($flutterwave))->createWallet(1, 'a@b.test', 'Ada', '1');
    }

    public function testCreditWalletByIdAddsToBalance()
    {
        $wallet = $this->wallet();

        $result = $this->service()->creditWalletById($wallet->user_id, 250);

        $this->assertTrue($result->is($wallet));
        $this->assertEquals(1250, $wallet->fresh()->balance);
    }

    public function testCreditWalletRejectsNonPositiveAmounts()
    {
        $wallet = $this->wallet();

        try {
            $this->service()->creditWalletById($wallet->user_id, 0);
            $this->fail('Expected a WalletException');
        } catch (WalletException $e) {
            $this->assertSame('wallet_invalid_amount', $e->errorCode());
        }

        $this->assertEquals(1000, $wallet->fresh()->balance);
    }

    public function testCreditWalletWithoutAWalletIsNotFound()
    {
        $this->expectException(WalletException::class);
        $this->expectExceptionMessage("You don't have a wallet");

        $this->service()->creditWalletById(User::factory()->create()->id, 10);
    }
}
