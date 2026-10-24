<?php

namespace Tests\Feature;

use App\Payment;
use App\Services\PaymentService;
use App\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Laravel\Passport\Passport;
use Tests\TestCase;

class PaymentControllerTest extends TestCase
{
    use RefreshDatabase;

    private function signIn(User $user): User
    {
        Passport::actingAs($user, [], 'api');

        return $user;
    }

    public function testIndexListsOnlyMyPayments()
    {
        $me = $this->signIn(User::factory()->create());
        Payment::factory()->create(['user_id' => $me->id]);
        Payment::factory()->count(2)->create();

        $this->getJson('/api/payment')->assertOk()->assertJsonCount(1, 'data');
    }

    public function testShowReturnsMyPaymentAndHidesOthers()
    {
        $me = $this->signIn(User::factory()->create());
        $mine = Payment::factory()->create(['user_id' => $me->id]);
        $theirs = Payment::factory()->create();

        $this->getJson("/api/payment/{$mine->id}")->assertOk()->assertJsonPath('data.id', $mine->id);
        $this->getJson("/api/payment/{$theirs->id}")->assertStatus(404);
    }

    public function testUpdateOnlyChangesTheDescriptionOfMyPayment()
    {
        $me = $this->signIn(User::factory()->create());
        $payment = Payment::factory()->create(['user_id' => $me->id, 'verified' => true]);

        $this->putJson("/api/payment/{$payment->id}", ['description' => 'Rent', 'verified' => false])->assertOk();

        $payment->refresh();
        $this->assertSame('Rent', $payment->description);
        $this->assertTrue((bool) $payment->verified);
    }

    public function testDestroyCannotRemoveSomeoneElsesPayment()
    {
        $this->signIn(User::factory()->create());
        $theirs = Payment::factory()->create();

        $this->deleteJson("/api/payment/{$theirs->id}")->assertStatus(404);

        $this->assertDatabaseHas('payments', ['id' => $theirs->id, 'deleted_at' => null]);
    }

    public function testPayWithCardReturnsTheAuthorisationUrl()
    {
        config(['services.flutterwave.rave_base_url' => 'https://rave.test', 'services.flutterwave.secret_key' => 'FLWSECK-abcdefabcdefabcdef']);
        Http::fake(['rave.test/*' => Http::response(['status' => 'success', 'data' => ['authurl' => 'https://bank.test/3ds']])]);
        $this->signIn(User::factory()->create(['name' => 'Ada Lovelace']));

        $this->postJson('/api/payment/card', [
            'cardno' => '5531886652142950', 'cvv' => '564', 'expirymonth' => '09', 'expiryyear' => '32',
            'currency' => 'NGN', 'country' => 'NG', 'amount' => '100', 'pin' => '3310',
        ])->assertOk()->assertJsonPath('data.authurl', 'https://bank.test/3ds');
    }

    public function testCardPayloadSplitsTheUsersName()
    {
        $user = User::factory()->make(['name' => 'Ada Lovelace', 'phone' => '0801']);

        $payload = app(PaymentService::class)->cardPayload($user, ['cardno' => '1', 'cvv' => 123]);

        $this->assertSame('Ada', $payload['firstname']);
        $this->assertSame('123', $payload['cvv']);
        $this->assertStringStartsWith('PD', $payload['txRef']);
    }

    public function testThirdPartyPaymentControllerReusesTheImplementation()
    {
        $this->assertInstanceOf(
            \App\Http\Controllers\Api\PaymentController::class,
            app(\App\Http\Controllers\ThirdPartyApi\PaymentController::class)
        );
    }
}
