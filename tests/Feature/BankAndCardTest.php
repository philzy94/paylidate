<?php

namespace Tests\Feature;

use App\Bank;
use App\User;
use App\VirtualCard;
use App\Wallet;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Laravel\Passport\Passport;
use Tests\TestCase;

class BankAndCardTest extends TestCase
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
        ]]);
    }

    private function signIn(User $user): User
    {
        Passport::actingAs($user, [], 'api');

        return $user;
    }

    private function bankPayload(array $overrides = []): array
    {
        return array_merge([
            'account_name' => 'Ada Lovelace',
            'bank_name' => 'GTB',
            'account_number' => '0123456789',
            'bank_code' => '058',
        ], $overrides);
    }

    private function wallet(User $user, $balance): Wallet
    {
        return Wallet::create([
            'user_id' => $user->id,
            'order_ref' => uniqid('URF'),
            'tx_ref' => uniqid('PD'),
            'account_number' => (string) random_int(1000000000, 9999999999),
            'balance' => $balance,
        ]);
    }

    // ----------------------------------------------------------------- banks

    public function testBankListReturnsOnlyTheCallersAccount()
    {
        $me = $this->signIn(User::factory()->create());
        Bank::create(array_merge($this->bankPayload(), ['user_id' => $me->id, 'branch_name' => 'x']));
        Bank::create(array_merge($this->bankPayload(['account_number' => '9999999999']), ['user_id' => User::factory()->create()->id, 'branch_name' => 'x']));

        $response = $this->getJson('/api/user-bank')->assertOk();

        $this->assertSame('0123456789', $response->json('data.account_number'));
    }

    public function testStoreSavesTheCallersBankAndReplacesIt()
    {
        $me = $this->signIn(User::factory()->create());

        $this->postJson('/api/user-bank', $this->bankPayload())->assertOk();
        $this->postJson('/api/user-bank', $this->bankPayload(['bank_name' => 'Access', 'user_id' => 999]))->assertOk();

        $this->assertSame(1, Bank::count());
        $this->assertSame('Access', Bank::first()->bank_name);
        $this->assertSame($me->id, Bank::first()->user_id);
    }

    public function testStoreValidatesTheAccountNumber()
    {
        $this->signIn(User::factory()->create());

        $this->postJson('/api/user-bank', $this->bankPayload(['account_number' => '123']))->assertStatus(422)->assertJsonValidationErrors('account_number');
    }

    public function testShowNeverRevealsAnotherUsersBank()
    {
        $other = User::factory()->create();
        Bank::create(array_merge($this->bankPayload(), ['user_id' => $other->id, 'branch_name' => 'x']));
        $this->signIn(User::factory()->create());

        $this->getJson("/api/user-bank/{$other->id}")->assertStatus(404)->assertJsonPath('code', 'bank_not_found');
    }

    public function testUpdateChangesOnlyTheCallersBank()
    {
        $me = $this->signIn(User::factory()->create());
        $other = User::factory()->create();
        Bank::create(array_merge($this->bankPayload(), ['user_id' => $me->id, 'branch_name' => 'x']));
        $theirs = Bank::create(array_merge($this->bankPayload(['bank_name' => 'Theirs']), ['user_id' => $other->id, 'branch_name' => 'x']));

        $this->putJson("/api/user-bank/{$other->id}", $this->bankPayload(['bank_name' => 'Mine']))->assertOk();

        $this->assertSame('Theirs', $theirs->fresh()->bank_name);
        $this->assertSame('Mine', Bank::where('user_id', $me->id)->first()->bank_name);
    }

    public function testAccountLookupResolvesTheHolderName()
    {
        Http::fake(['flw.test/*' => Http::response(['status' => 'success', 'data' => ['account_name' => 'ADA L', 'account_number' => '0123456789']])]);

        $this->postJson('/api/verify-account', ['account_number' => '0123456789', 'bank_code' => '058'])
            ->assertOk()
            ->assertJsonPath('data.account_name', 'ADA L');
    }

    public function testAccountLookupFailureIsReported()
    {
        Http::fake(['flw.test/*' => Http::response(['status' => 'error', 'message' => 'bad'])]);

        $this->postJson('/api/verify-account', ['account_number' => '0123456789', 'bank_code' => '058'])
            ->assertStatus(422)
            ->assertJsonPath('code', 'bank_invalid_account');
    }

    // ----------------------------------------------------------------- cards

    public function testCardCreationIsPaidFromTheWalletAndKeepsTheFee()
    {
        Http::fake(['flw.test/*' => Http::response(['status' => 'success', 'data' => ['id' => 'card-1', 'account_id' => 'acc-1', 'currency' => 'NGN']])]);
        $user = $this->signIn(User::factory()->create());
        $wallet = $this->wallet($user, 5000);

        $this->postJson('/api/card', ['currency' => 'NGN', 'amount' => 1000, 'label' => 'Shopping'])->assertOk();

        Http::assertSent(fn ($request) => $request['amount'] == 900 && $request['currency'] === 'NGN');
        $this->assertEquals(4000, $wallet->fresh()->balance);
        $this->assertDatabaseHas('virtual_cards', ['user_id' => $user->id, 'card_id' => 'card-1', 'label' => 'Shopping']);
    }

    public function testCardCreationNeedsEnoughWalletBalance()
    {
        Http::fake();
        $user = $this->signIn(User::factory()->create());
        $this->wallet($user, 50);

        $this->postJson('/api/card', ['currency' => 'NGN', 'amount' => 1000])->assertStatus(400)->assertJsonPath('code', 'wallet_insufficient_funds');

        Http::assertNothingSent();
        $this->assertSame(0, VirtualCard::count());
    }

    public function testAProviderFailureRefundsTheWallet()
    {
        Http::fake(['flw.test/*' => Http::response(['status' => 'error', 'message' => 'nope'])]);
        $user = $this->signIn(User::factory()->create());
        $wallet = $this->wallet($user, 5000);

        $this->postJson('/api/card', ['currency' => 'NGN', 'amount' => 1000])->assertStatus(502)->assertJsonPath('code', 'card_provider_failed');

        $this->assertEquals(5000, $wallet->fresh()->balance);
        $this->assertSame(0, VirtualCard::count());
    }

    public function testCardAmountMustCoverTheFee()
    {
        $user = $this->signIn(User::factory()->create());
        $this->wallet($user, 5000);

        $this->postJson('/api/card', ['currency' => 'NGN', 'amount' => 100])->assertStatus(422)->assertJsonPath('code', 'card_amount_too_low');
    }

    public function testFundingAnotherUsersCardIsRefused()
    {
        Http::fake();
        $owner = User::factory()->create();
        VirtualCard::create(['user_id' => $owner->id, 'card_id' => 'card-9', 'account_id' => 'a', 'currency' => 'NGN']);
        $thief = $this->signIn(User::factory()->create());
        $this->wallet($thief, 5000);

        $this->postJson('/api/fund', ['virtual_card_id' => 'card-9', 'amount' => 500])->assertStatus(404)->assertJsonPath('code', 'card_not_found');

        Http::assertNothingSent();
    }

    public function testFundingOwnCardDebitsTheWallet()
    {
        Http::fake(['flw.test/*' => Http::response(['status' => 'success', 'data' => ['id' => 'tx']])]);
        $user = $this->signIn(User::factory()->create());
        $wallet = $this->wallet($user, 2000);
        VirtualCard::create(['user_id' => $user->id, 'card_id' => 'card-1', 'account_id' => 'a', 'currency' => 'NGN']);

        $this->postJson('/api/fund', ['virtual_card_id' => 'card-1', 'amount' => 500])->assertOk();

        $this->assertEquals(1500, $wallet->fresh()->balance);
    }

    public function testCardListIncludesProviderDetailsForOwnCardsOnly()
    {
        Http::fake(['flw.test/*' => Http::response(['status' => 'success', 'data' => ['masked_pan' => '5399***']])]);
        $user = $this->signIn(User::factory()->create());
        VirtualCard::create(['user_id' => $user->id, 'card_id' => 'mine', 'account_id' => 'a', 'currency' => 'NGN']);
        VirtualCard::create(['user_id' => User::factory()->create()->id, 'card_id' => 'theirs', 'account_id' => 'a', 'currency' => 'NGN']);

        $response = $this->getJson('/api/card')->assertOk();

        $this->assertCount(1, $response->json('data'));
        $this->assertSame('5399***', $response->json('data.0.data.masked_pan'));
    }
}
