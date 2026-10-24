<?php

namespace Tests\Unit\Services;

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

    public function testCreateWalletReturnsProviderErrorMessage()
    {
        config(['services.flutterwave.base_url' => 'https://flw.test']);
        Http::fake(['flw.test/*' => Http::response(['status' => 'error', 'message' => 'Invalid BVN'])]);

        $result = $this->service()->createWallet(1, 'a@b.test', 'Ada', '1');

        $this->assertSame(['status' => 'error', 'message' => 'Invalid BVN'], $result);
        $this->assertSame(0, Wallet::count());
    }

    public function testCreditWalletByIdAddsToBalance()
    {
        $wallet = $this->wallet();

        $result = $this->service()->creditWalletById($wallet->user_id, 250);

        $this->assertSame('success', $result['status']);
        $this->assertEquals(1250, $wallet->fresh()->balance);
    }

    public function testCreditWalletRejectsNonPositiveAmounts()
    {
        $wallet = $this->wallet();

        $result = $this->service()->creditWalletById($wallet->user_id, 0);

        $this->assertSame('error', $result['status']);
        $this->assertEquals(1000, $wallet->fresh()->balance);
    }

    public function testCreditWalletByTxRef()
    {
        $wallet = $this->wallet(['tx_ref' => 'PD_FIND_ME']);

        $this->service()->creditWalletBytx_ref('PD_FIND_ME', 500);

        $this->assertEquals(1500, $wallet->fresh()->balance);
    }

    public function testDebitWalletSubtractsFromBalance()
    {
        $wallet = $this->wallet();

        $result = $this->service()->debitWallet($wallet->user_id, 400);

        $this->assertSame('success', $result['status']);
        $this->assertEquals(600, $wallet->fresh()->balance);
    }

    public function testDebitWalletRefusesToOverdraw()
    {
        $wallet = $this->wallet(['balance' => 100]);

        $result = $this->service()->debitWallet($wallet->user_id, 101);

        $this->assertSame('insufficient fund', $result['message']);
        $this->assertEquals(100, $wallet->fresh()->balance);
    }

    public function testDebitWalletWithoutAWallet()
    {
        $result = $this->service()->debitWallet(User::factory()->create()->id, 10);

        $this->assertSame("You don't have a wallet", $result['message']);
    }

    public function testTransferBonusToWalletMovesFundsWhenBonusIsEligible()
    {
        $wallet = $this->wallet(['balance' => 0, 'bonus' => 5000]);

        $this->service()->transferBonusToWallet($wallet->user_id, 2000);

        $wallet->refresh();
        $this->assertEquals(2000, $wallet->balance);
        $this->assertEquals(3000, $wallet->bonus);
    }

    public function testTransferBonusRequiresAThreeThousandBonus()
    {
        $wallet = $this->wallet(['bonus' => 2500]);

        $result = $this->service()->transferBonusToWallet($wallet->user_id, 100);

        $this->assertSame('Bonus must be up to 3000 before it can be withdrawn', $result['message']);
    }

    public function testTransferBonusCannotExceedTheBonusBalance()
    {
        $wallet = $this->wallet(['bonus' => 5000]);

        $result = $this->service()->transferBonusToWallet($wallet->user_id, 6000);

        $this->assertSame('insufficient bonus', $result['message']);
    }

    public function testCreditWalletBonusByIdAndByAccountNumber()
    {
        $wallet = $this->wallet(['bonus' => 0, 'account_number' => '0011223344']);

        $this->service()->creditWalletBonusById($wallet->user_id, 100);
        $this->service()->creditWalletBonusByAccountNumber('0011223344', 50);

        $this->assertEquals(150, $wallet->fresh()->bonus);
    }

    public function testWalletHistoryIsRecorded()
    {
        $wallet = $this->wallet();

        $result = $this->service()->walletHistory($wallet->user_id, 'credit', 100, 'Top up', $wallet->id, 1000, 1100);

        $this->assertSame('success', $result['status']);
        $this->assertDatabaseHas('wallet_histories', ['wallet_id' => $wallet->id, 'type' => 'credit', 'amount' => 100]);
    }
}
