<?php

namespace Tests\Unit\Services;

use App\Services\FlutterwaveService;
use App\Services\WalletService;
use App\User;
use App\Wallet;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class WalletLegacyMethodsTest extends TestCase
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

    public function testCreditWalletByIdAddsToTheBalance()
    {
        $wallet = $this->wallet();

        $result = $this->service()->creditWalletById($wallet->user_id, 250);

        $this->assertSame('success', $result['status']);
        $this->assertEquals(1250, $wallet->fresh()->balance);
    }

    public function testCreditWalletByIdRejectsNonPositiveAmounts()
    {
        $wallet = $this->wallet();

        $result = $this->service()->creditWalletById($wallet->user_id, 0);

        $this->assertSame('error', $result['status']);
        $this->assertEquals(1000, $wallet->fresh()->balance);
    }

    public function testCreditWalletByIdReportsAnUnknownWalletAsAnError()
    {
        $result = $this->service()->creditWalletById(999999, 10);

        $this->assertSame('error', $result['status']);
    }

    public function testCreditWalletByTxRefHandlesMissingWallets()
    {
        $result = $this->service()->creditWalletBytx_ref('PD_UNKNOWN', 10);

        $this->assertSame("You don't have a wallet", $result['message']);
    }

    public function testDebitWalletSubtractsFromTheBalance()
    {
        $wallet = $this->wallet();

        $result = $this->service()->debitWallet($wallet->user_id, 400);

        $this->assertSame('success', $result['status']);
        $this->assertEquals(600, $wallet->fresh()->balance);
    }

    public function testDebitWalletRefusesToOverdraw()
    {
        $wallet = $this->wallet();

        $result = $this->service()->debitWallet($wallet->user_id, 1001);

        $this->assertSame('insufficient fund', $result['message']);
        $this->assertEquals(1000, $wallet->fresh()->balance);
    }

    public function testDebitWalletRejectsNonPositiveAmounts()
    {
        $wallet = $this->wallet();

        $result = $this->service()->debitWallet($wallet->user_id, -5);

        $this->assertSame('error', $result['status']);
        $this->assertEquals(1000, $wallet->fresh()->balance);
    }

    public function testDebitWalletHandlesMissingWallets()
    {
        $result = $this->service()->debitWallet(999999, 10);

        $this->assertSame("You don't have a wallet", $result['message']);
    }

    public function testTransferBonusRejectsNonPositiveAmountsAndMissingWallets()
    {
        $wallet = $this->wallet();

        $this->assertSame('error', $this->service()->transferBonusToWallet($wallet->user_id, 0)['status']);
        $this->assertSame("You don't have a wallet", $this->service()->transferBonusToWallet(999999, 100)['message']);
    }

    public function testBonusCreditsRejectNonPositiveAmountsAndMissingWallets()
    {
        $wallet = $this->wallet();

        $this->assertSame('error', $this->service()->creditWalletBonusById($wallet->user_id, 0)['status']);
        $this->assertSame("You don't have a wallet", $this->service()->creditWalletBonusById(999999, 10)['message']);
        $this->assertSame('error', $this->service()->creditWalletBonusByAccountNumber('7824822527', -1)['status']);
        $this->assertSame("You don't have a wallet", $this->service()->creditWalletBonusByAccountNumber('0000000000', 10)['message']);
    }

    public function testLegacyMethodsReturnAGenericErrorWhenTheDatabaseFails()
    {
        $wallet = $this->wallet();
        DB::statement('DROP TABLE wallets');

        $this->assertSame('An error occured. Please try again', $this->service()->creditWalletById($wallet->user_id, 10)['message']);
        $this->assertSame('An error occured. Please try again', $this->service()->debitWallet($wallet->user_id, 10)['message']);
        $this->assertSame('An error occured. Please try again', $this->service()->creditWalletBytx_ref('x', 10)['message']);
        $this->assertSame('An error occured. Please try again', $this->service()->transferBonusToWallet($wallet->user_id, 10)['message']);
        $this->assertSame('An error occured. Please try again', $this->service()->creditWalletBonusById($wallet->user_id, 10)['message']);
        $this->assertSame('An error occured. Please try again', $this->service()->creditWalletBonusByAccountNumber('1', 10)['message']);
    }
}
