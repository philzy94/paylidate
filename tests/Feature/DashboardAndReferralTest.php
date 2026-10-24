<?php

namespace Tests\Feature;

use App\Bank;
use App\Dispute;
use App\Product;
use App\Referer;
use App\Transaction;
use App\User;
use App\Wallet;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Passport\Passport;
use Tests\TestCase;

class DashboardAndReferralTest extends TestCase
{
    use RefreshDatabase;

    private function signIn(User $user): User
    {
        Passport::actingAs($user, [], 'api');

        return $user;
    }

    public function testDashboardRequiresAuthentication()
    {
        $this->getJson('/api/dashboard')->assertStatus(401);
    }

    public function testDashboardSummarisesTheCallersActivity()
    {
        $user = $this->signIn(User::factory()->create());
        $product = Product::factory()->create();
        $done = Transaction::create(['user_id' => $user->id, 'product_id' => $product->id, 'quantity' => 1, 'status' => 1, 'amount' => 800]);
        Transaction::create(['user_id' => $user->id, 'product_id' => $product->id, 'quantity' => 1, 'status' => 0]);
        Dispute::create(['user_id' => $user->id, 'transaction_id' => $done->id, 'subject' => 's', 'dispute' => 'd']);
        Wallet::create(['user_id' => $user->id, 'order_ref' => 'o', 'tx_ref' => 't', 'account_number' => '1', 'balance' => 300, 'bonus' => 50]);
        Referer::create(['user_id' => $user->id, 'amount' => 75]);

        $data = $this->getJson('/api/dashboard')->assertOk()->json('data');

        $this->assertEquals(800, $data['payments_received']);
        $this->assertSame(1, $data['active_transaction']);
        $this->assertSame(1, $data['active_dsiputes']);
        $this->assertEquals(75, $data['refer']);
        $this->assertEquals(300, $data['balance']['balance']);
        $this->assertEquals(50, $data['bonus']['bonus']);
    }

    public function testDashboardForAUserWithoutAWalletShowsZeroBalances()
    {
        $this->signIn(User::factory()->create());

        $data = $this->getJson('/api/dashboard')->assertOk()->json('data');

        $this->assertSame(0, $data['balance']);
        $this->assertSame(0, $data['bonus']);
    }

    public function testReferralBonusListShowsOnlyEarnedBonusesAndTheBank()
    {
        $user = $this->signIn(User::factory()->create());
        Bank::create(['user_id' => $user->id, 'bank_name' => 'GTB', 'account_number' => '0123456789', 'bank_code' => '058', 'account_name' => 'A', 'branch_name' => 'x']);
        Referer::create(['user_id' => $user->id, 'amount' => 100])->forceFill(['transaction_status' => true])->save();
        Referer::create(['user_id' => $user->id, 'amount' => 200]);
        Referer::create(['user_id' => User::factory()->create()->id, 'amount' => 300])->forceFill(['transaction_status' => true])->save();

        $response = $this->getJson('/api/referral-bonus')->assertOk();

        $this->assertCount(1, $response->json('data.0'));
        $this->assertSame('0123456789', $response->json('data.1.account_number'));
    }

    public function testReferralSummary()
    {
        $user = $this->signIn(User::factory()->create());
        User::factory()->count(2)->create(['referred_by' => (string) $user->id]);
        Referer::create(['user_id' => $user->id, 'amount' => 400])->forceFill(['transaction_status' => true, 'withdrawal_status' => true])->save();
        Referer::create(['user_id' => $user->id, 'amount' => 600])->forceFill(['transaction_status' => true])->save();

        $this->getJson('/api/referral')
            ->assertOk()
            ->assertJsonPath('data.referred_users', 2)
            ->assertJsonPath('data.total_earned', 1000)
            ->assertJsonPath('data.available', 600)
            ->assertJsonPath('data.minimum_payout', 3000)
            ->assertJsonPath('data.referral_token', $user->referral_token);
    }

    public function testPublicHelpersAreValidatedAndAuthenticatedPaymentHelpersWork()
    {
        $this->postJson('/api/get-rate', [])->assertStatus(422);
        $this->signIn(User::factory()->create());
        $this->postJson('/api/payment/verify', [])->assertStatus(422)->assertJsonValidationErrors('flw_T_id');
        $this->postJson('/api/payment/validate', [])->assertStatus(422)->assertJsonValidationErrors(['flwRef', 'otp']);
    }
}
