<?php

namespace Tests\Feature;

use App\Mail\WalletCreated;
use App\User;
use App\Wallet;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Laravel\Passport\Passport;
use Tests\TestCase;

class WalletEndpointsTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config(['services.flutterwave' => [
            'public_key' => 'pub',
            'secret_key' => 'test-secret-key',
            'base_url' => 'https://flw.test',
            'rave_base_url' => 'https://rave.test',
            'webhook_hash' => 'hook-secret',
        ]]);
    }

    private function walletFor(User $user, array $attributes = []): Wallet
    {
        return Wallet::create(array_merge([
            'user_id' => $user->id,
            'order_ref' => uniqid('URF_'),
            'tx_ref' => uniqid('PD_'),
            'account_number' => (string) random_int(1000000000, 9999999999),
            'balance' => 1000,
        ], $attributes));
    }

    private function signIn(User $user): User
    {
        Passport::actingAs($user, [], 'api');

        return $user;
    }

    public function testCreateWalletOpensAVirtualAccountAndEmailsTheUser()
    {
        Mail::fake();
        Http::fake(['flw.test/*' => Http::response(['status' => 'success', 'data' => ['order_ref' => 'URF', 'account_number' => '0099887766', 'bank_name' => 'WEMA']])]);
        $user = $this->signIn(User::factory()->create());

        $this->postJson('/api/create-wallet', ['bvn' => '12345678901'])
            ->assertStatus(201)
            ->assertJsonPath('data.account_number', '0099887766');

        Mail::assertSent(WalletCreated::class);
        $this->assertSame(1, Wallet::where('user_id', $user->id)->count());
    }

    public function testCreateWalletRequiresAnElevenDigitBvn()
    {
        $this->signIn(User::factory()->create());

        $this->postJson('/api/create-wallet', ['bvn' => '123'])->assertStatus(422)->assertJsonValidationErrors('bvn');
    }

    public function testCreateWalletTwiceIsRejected()
    {
        $user = $this->signIn(User::factory()->create());
        $this->walletFor($user);

        $this->postJson('/api/create-wallet', ['bvn' => '12345678901'])->assertStatus(409)->assertJsonPath('code', 'wallet_exists');
    }

    public function testCreateWalletSurfacesProviderErrors()
    {
        Http::fake(['flw.test/*' => Http::response(['status' => 'error', 'message' => 'Invalid BVN'])]);
        $this->signIn(User::factory()->create());

        $this->postJson('/api/create-wallet', ['bvn' => '12345678901'])->assertStatus(502)->assertJsonPath('message', 'Invalid BVN');
    }

    public function testGetWalletReturnsTheOwnersWalletWithHistory()
    {
        $user = $this->signIn(User::factory()->create());
        $this->walletFor($user, ['balance' => 250]);

        $response = $this->getJson('/api/get-wallet')->assertOk();
        $this->assertEquals(250, $response->json('data.balance'));
    }

    public function testTopUpCreditsTheAmountFlutterwaveReports()
    {
        Http::fake(['rave.test/*' => Http::response(['status' => 'success', 'data' => ['status' => 'successful', 'amount' => 500, 'currency' => 'NGN']])]);
        $user = $this->signIn(User::factory()->create());
        $wallet = $this->walletFor($user, ['balance' => 1000]);

        $this->postJson('/api/credit-wallet', [
            'tx_ref' => 'PD_REF_1',
            'narration' => 'Top up',
            'amount' => 99999999,
        ])->assertOk();

        $this->assertEquals(1500, $wallet->fresh()->balance);
        $this->assertDatabaseHas('wallet_histories', ['wallet_id' => $wallet->id, 'type' => 'credit', 'amount' => 500, 'balance_after' => 1500]);
    }

    public function testTopUpIsRefusedWhenFlutterwaveDoesNotConfirmThePayment()
    {
        Http::fake(['rave.test/*' => Http::response(['status' => 'success', 'data' => ['status' => 'failed', 'amount' => 500, 'currency' => 'NGN']])]);
        $user = $this->signIn(User::factory()->create());
        $wallet = $this->walletFor($user);

        $this->postJson('/api/credit-wallet', ['tx_ref' => 'FAKE', 'narration' => 'x'])
            ->assertStatus(422)
            ->assertJsonPath('code', 'wallet_payment_unverified');

        $this->assertEquals(1000, $wallet->fresh()->balance);
    }

    public function testTheSamePaymentCannotCreditTwice()
    {
        Http::fake(['rave.test/*' => Http::response(['status' => 'success', 'data' => ['status' => 'successful', 'amount' => 500, 'currency' => 'NGN']])]);
        $user = $this->signIn(User::factory()->create());
        $wallet = $this->walletFor($user);
        $payload = ['tx_ref' => 'PD_REF_2', 'narration' => 'Top up'];

        $this->postJson('/api/credit-wallet', $payload)->assertOk();
        $this->postJson('/api/credit-wallet', $payload)->assertStatus(422)->assertJsonPath('code', 'wallet_duplicate');

        $this->assertEquals(1500, $wallet->fresh()->balance);
    }

    public function testTopUpNeedsAWallet()
    {
        $this->signIn(User::factory()->create());

        $this->postJson('/api/credit-wallet', ['tx_ref' => 'x', 'narration' => 'x'])->assertStatus(404)->assertJsonPath('code', 'wallet_not_found');
    }

    public function testDebitReducesTheBalanceAndWritesHistory()
    {
        $user = $this->signIn(User::factory()->create());
        $wallet = $this->walletFor($user, ['balance' => 1000]);

        $this->postJson('/api/debit-wallet', ['amount' => 300, 'narration' => 'Buy'])->assertOk();

        $this->assertEquals(700, $wallet->fresh()->balance);
        $this->assertDatabaseHas('wallet_histories', ['wallet_id' => $wallet->id, 'type' => 'debit', 'balance_before' => 1000, 'balance_after' => 700]);
    }

    public function testDebitCannotOverdraw()
    {
        $user = $this->signIn(User::factory()->create());
        $wallet = $this->walletFor($user, ['balance' => 100]);

        $this->postJson('/api/debit-wallet', ['amount' => 300, 'narration' => 'Buy'])
            ->assertStatus(400)
            ->assertJsonPath('code', 'wallet_insufficient_funds');

        $this->assertEquals(100, $wallet->fresh()->balance);
    }

    public function testDebitRejectsNegativeAmounts()
    {
        $user = $this->signIn(User::factory()->create());
        $this->walletFor($user);

        $this->postJson('/api/debit-wallet', ['amount' => -500, 'narration' => 'Steal'])->assertStatus(422);
    }

    public function testWalletHistoryListsOnlyMyEntries()
    {
        $user = $this->signIn(User::factory()->create());
        $wallet = $this->walletFor($user);
        $this->postJson('/api/debit-wallet', ['amount' => 10, 'narration' => 'a'])->assertOk();
        $other = $this->walletFor(User::factory()->create());
        \App\WalletHistory::create(['user_id' => $other->user_id, 'wallet_id' => $other->id, 'type' => 'credit', 'amount' => 1, 'narration' => 'n', 'balance_before' => 0, 'balance_after' => 1]);

        $this->getJson('/api/get-wallet-history')->assertOk()->assertJsonCount(1, 'data');
    }

    // ------------------------------------------------------------- webhook

    private function webhook(array $overrides = [], ?string $hash = 'hook-secret')
    {
        $payload = ['data' => array_merge(['tx_ref' => 'PD_HOOK', 'amount' => 700, 'id' => 9001, 'status' => 'successful'], $overrides)];

        return $this->postJson('/settlement_notif', $payload, $hash ? ['verif-hash' => $hash] : []);
    }

    public function testWebhookCreditsTheWalletOnce()
    {
        $wallet = $this->walletFor(User::factory()->create(), ['tx_ref' => 'PD_HOOK', 'balance' => 0]);

        $this->webhook()->assertOk();
        $this->webhook()->assertStatus(422);

        $this->assertEquals(700, $wallet->fresh()->balance);
        $this->assertSame(1, \App\WalletHistory::where('wallet_id', $wallet->id)->count());
    }

    public function testWebhookRejectsAWrongOrMissingSignature()
    {
        $wallet = $this->walletFor(User::factory()->create(), ['tx_ref' => 'PD_HOOK', 'balance' => 0]);

        $this->webhook([], 'wrong')->assertStatus(403);
        $this->webhook([], null)->assertStatus(403);

        $this->assertEquals(0, $wallet->fresh()->balance);
    }

    public function testWebhookIsRejectedWhenNoSecretIsConfigured()
    {
        config(['services.flutterwave.webhook_hash' => null]);
        $this->walletFor(User::factory()->create(), ['tx_ref' => 'PD_HOOK']);

        $this->webhook([], '')->assertStatus(403);
    }

    public function testWebhookIgnoresUnsuccessfulTransfers()
    {
        $wallet = $this->walletFor(User::factory()->create(), ['tx_ref' => 'PD_HOOK', 'balance' => 0]);

        $this->webhook(['status' => 'failed'])->assertStatus(400);

        $this->assertEquals(0, $wallet->fresh()->balance);
    }

    public function testWebhookForAnUnknownAccountIsRejected()
    {
        $this->webhook(['tx_ref' => 'UNKNOWN'])->assertStatus(400)->assertJsonPath('code', 'wallet_invalid_amount');
    }
}
