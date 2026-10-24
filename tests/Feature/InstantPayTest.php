<?php

namespace Tests\Feature;

use App\Instandpay;
use App\Services\SmsService;
use App\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Http;
use Laravel\Passport\Passport;
use Mockery;
use Tests\TestCase;

class InstantPayTest extends TestCase
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

    private function sms()
    {
        $sms = Mockery::mock(SmsService::class);
        $this->app->instance(SmsService::class, $sms);

        return $sms;
    }

    private function receivedTransfer(User $receiver, array $attributes = []): Instandpay
    {
        $sender = User::factory()->create();

        return Instandpay::create(array_merge([
            'user_id' => $sender->id,
            'receiver_id' => $receiver->id,
            'receiver_name' => $receiver->name,
            'receiver_number' => $receiver->phone,
            'sender_email' => $sender->email,
            'sender_name' => $sender->name,
            'amount' => 5000,
            'payment_ref' => uniqid('ref'),
            'link_token' => 'PD_IP_TOKEN',
            'tracking_id' => '123',
            'withdrawal_pin' => Hash::make('654321'),
        ], $attributes));
    }

    public function testVerifyUserReturnsTheReceiversName()
    {
        $sender = $this->signIn(User::factory()->create(['phone' => '08011111111']));
        User::factory()->create(['phone' => '08022222222', 'name' => 'Grace Hopper']);

        $this->getJson('/api/verify-number/08022222222')->assertOk()->assertJsonPath('name', 'Grace Hopper');
    }

    public function testVerifyUserRejectsUnknownAndOwnNumbers()
    {
        $this->signIn(User::factory()->create(['phone' => '08011111111']));

        $this->getJson('/api/verify-number/08099999999')->assertStatus(400)->assertJsonPath('code', 'instantpay_receiver_unknown');
        $this->getJson('/api/verify-number/123')->assertStatus(400);
        $this->getJson('/api/verify-number/08011111111')->assertStatus(400)->assertJsonPath('code', 'instantpay_to_self');
    }

    public function testTransferUsesTheVerifiedAmountAndTextsThePin()
    {
        Http::fake(['rave.test/*' => Http::response(['status' => 'success', 'data' => ['status' => 'successful', 'amount' => 2500, 'currency' => 'NGN']])]);
        $sms = $this->sms();
        $sms->shouldReceive('sendToNigerianNumber')->once()->withArgs(function ($number, $body) {
            return $number === '08022222222' && str_contains($body, 'NGN2500') && preg_match('/pin is \d{6}/', $body);
        });
        $sender = $this->signIn(User::factory()->create(['phone' => '08011111111']));
        $receiver = User::factory()->create(['phone' => '08022222222']);

        $response = $this->postJson('/api/instant-pay/tranfer', [
            'receiver_number' => '08022222222',
            'payment_ref' => 'FLW-REF-1',
            'amount' => 99999999,
            'sender_email' => 'spoof@example.test',
        ])->assertOk();

        $transfer = Instandpay::first();
        $this->assertEquals(2500, $transfer->amount);
        $this->assertSame($sender->email, $transfer->sender_email);
        $this->assertSame($receiver->id, $transfer->receiver_id);
        $this->assertStringStartsWith('$2y$', $transfer->getRawOriginal('withdrawal_pin'));
        $this->assertArrayNotHasKey('withdrawal_pin', $response->json('data'));
    }

    public function testTransferIsRefusedForAnUnverifiedPayment()
    {
        Http::fake(['rave.test/*' => Http::response(['status' => 'error', 'data' => ['status' => 'failed']])]);
        $this->sms()->shouldNotReceive('sendToNigerianNumber');
        $this->signIn(User::factory()->create(['phone' => '08011111111']));
        User::factory()->create(['phone' => '08022222222']);

        $this->postJson('/api/instant-pay/tranfer', ['receiver_number' => '08022222222', 'payment_ref' => 'FAKE'])
            ->assertStatus(422)
            ->assertJsonPath('code', 'instantpay_payment_unverified');

        $this->assertSame(0, Instandpay::count());
    }

    public function testAPaymentReferenceCanOnlyFundOneTransfer()
    {
        Http::fake(['rave.test/*' => Http::response(['status' => 'success', 'data' => ['status' => 'successful', 'amount' => 100, 'currency' => 'NGN']])]);
        $this->sms()->shouldReceive('sendToNigerianNumber')->once();
        $this->signIn(User::factory()->create(['phone' => '08011111111']));
        User::factory()->create(['phone' => '08022222222']);
        $payload = ['receiver_number' => '08022222222', 'payment_ref' => 'ONCE'];

        $this->postJson('/api/instant-pay/tranfer', $payload)->assertOk();
        $this->postJson('/api/instant-pay/tranfer', $payload)->assertStatus(400)->assertJsonPath('code', 'instantpay_payment_used');
    }

    public function testHistoryListsSentAndReceived()
    {
        $me = $this->signIn(User::factory()->create(['phone' => '08033333333']));
        $this->receivedTransfer($me);
        Instandpay::where('receiver_id', $me->id)->update(['user_id' => $me->id]);

        $this->getJson('/api/instant-pay/history-send')->assertOk()->assertJsonCount(1, 'data');
        $this->getJson('/api/instant-pay/history-receive')->assertOk()->assertJsonCount(1, 'data');
    }

    public function testClaimStoresTheBankLookupResultNotClientValues()
    {
        Http::fake(['flw.test/*' => Http::response(['status' => 'success', 'data' => ['account_name' => 'GRACE HOPPER']])]);
        $receiver = $this->signIn(User::factory()->create(['phone' => '08022222222']));
        $transfer = $this->receivedTransfer($receiver);

        $this->postJson('/api/instant-pay/verify', [
            'withdrawal_pin' => 654321,
            'link_token' => 'PD_IP_TOKEN',
            'bank_code' => '058',
            'account_number' => '0123456789',
            'account_name' => 'SPOOFED NAME',
        ])->assertOk();

        $transfer->refresh();
        $this->assertSame('GRACE HOPPER', $transfer->account_name);
        $this->assertSame('0123456789', $transfer->account_number);
    }

    public function testClaimRejectsAWrongPinAndOtherUsers()
    {
        $receiver = User::factory()->create(['phone' => '08022222222']);
        $this->receivedTransfer($receiver);
        $payload = ['withdrawal_pin' => 111111, 'link_token' => 'PD_IP_TOKEN', 'bank_code' => '058', 'account_number' => '0123456789'];

        $this->signIn($receiver);
        $this->postJson('/api/instant-pay/verify', $payload)->assertStatus(400)->assertJsonPath('code', 'instantpay_invalid_pin');

        $this->signIn(User::factory()->create());
        $this->postJson('/api/instant-pay/verify', array_merge($payload, ['withdrawal_pin' => 654321]))
            ->assertStatus(403)
            ->assertJsonPath('code', 'instantpay_forbidden');
    }

    public function testClaimHonoursLegacyPlainTextPins()
    {
        Http::fake(['flw.test/*' => Http::response(['status' => 'success', 'data' => ['account_name' => 'X']])]);
        $receiver = $this->signIn(User::factory()->create(['phone' => '08022222222']));
        $this->receivedTransfer($receiver, ['withdrawal_pin' => '222222']);

        $this->postJson('/api/instant-pay/verify', ['withdrawal_pin' => 222222, 'link_token' => 'PD_IP_TOKEN', 'bank_code' => '058', 'account_number' => '0123456789'])->assertOk();
    }

    public function testClaimFailsWhenTheBankAccountCannotBeResolved()
    {
        Http::fake(['flw.test/*' => Http::response(['status' => 'error', 'message' => 'Invalid account'])]);
        $receiver = $this->signIn(User::factory()->create(['phone' => '08022222222']));
        $this->receivedTransfer($receiver);

        $this->postJson('/api/instant-pay/verify', ['withdrawal_pin' => 654321, 'link_token' => 'PD_IP_TOKEN', 'bank_code' => '058', 'account_number' => '0123456789'])
            ->assertStatus(422)
            ->assertJsonPath('code', 'instantpay_account_invalid');
    }

    public function testWithdrawPaysTheStoredAmountToTheVerifiedAccountOnce()
    {
        Http::fake(['flw.test/*' => Http::response(['status' => 'success', 'data' => ['id' => 1]])]);
        $receiver = $this->signIn(User::factory()->create(['phone' => '08022222222']));
        $transfer = $this->receivedTransfer($receiver, ['bank_code' => '058', 'account_number' => '0123456789']);

        $this->postJson('/api/instant-pay/withdraw', ['id' => $transfer->id])->assertOk();
        $this->postJson('/api/instant-pay/withdraw', ['id' => $transfer->id])->assertStatus(409);

        Http::assertSentCount(1);
        Http::assertSent(fn ($request) => $request['amount'] == 5000 && $request['account_number'] === '0123456789');
        $this->assertTrue((bool) $transfer->fresh()->status);
    }

    public function testOnlyTheReceiverCanWithdraw()
    {
        Http::fake();
        $receiver = User::factory()->create(['phone' => '08022222222']);
        $transfer = $this->receivedTransfer($receiver, ['bank_code' => '058', 'account_number' => '0123456789']);
        $this->signIn(User::factory()->create());

        $this->postJson('/api/instant-pay/withdraw', ['id' => $transfer->id])->assertStatus(403);

        Http::assertNothingSent();
    }

    public function testWithdrawNeedsAVerifiedBankAccount()
    {
        Http::fake();
        $receiver = $this->signIn(User::factory()->create(['phone' => '08022222222']));
        $transfer = $this->receivedTransfer($receiver);

        $this->postJson('/api/instant-pay/withdraw', ['id' => $transfer->id])->assertStatus(422)->assertJsonPath('code', 'instantpay_not_ready');
    }

    public function testAFailedTransferKeepsTheMoneyWithdrawable()
    {
        Http::fake(['flw.test/*' => Http::response(['status' => 'error', 'message' => 'Insufficient balance'])]);
        $receiver = $this->signIn(User::factory()->create(['phone' => '08022222222']));
        $transfer = $this->receivedTransfer($receiver, ['bank_code' => '058', 'account_number' => '0123456789']);

        $this->postJson('/api/instant-pay/withdraw', ['id' => $transfer->id])->assertStatus(502);

        $this->assertFalse((bool) $transfer->fresh()->status);
    }
}
