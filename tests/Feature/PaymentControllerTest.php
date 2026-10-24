<?php

namespace Tests\Feature;

use App\Payment;
use App\Product;
use App\Referer;
use App\Services\PaymentService;
use App\Transaction;
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

    public function testMakePaymentAppliesReferralAmountToTransactionAndReferrer()
    {
        $this->signIn(User::factory()->create());
        $referral = Referer::create(['user_id' => User::factory()->create()->id, 'amount' => 0]);
        $product = Product::factory()->create(['price' => 1000, 'referral_amount' => 100, 'quantity' => 10]);
        $transaction = Transaction::create([
            'product_id' => $product->id,
            'quantity' => 2,
            'referer_id' => $referral->id,
        ]);

        $this->postJson('/api/make-payment', ['payment_id' => 'P-1', 'transaction_id' => $transaction->id])->assertOk();

        $this->assertEquals(1800, $transaction->fresh()->amount);
        $this->assertEquals(200, $referral->fresh()->amount);
        $this->assertSame(8, $product->fresh()->quantity);
    }

    public function testMakePaymentIsRejectedTwiceForTheSamePaymentId()
    {
        $this->signIn(User::factory()->create());
        $product = Product::factory()->create(['quantity' => 10, 'referral_amount' => 0]);
        $transaction = Transaction::create(['product_id' => $product->id, 'quantity' => 1]);
        $payload = ['payment_id' => 'P-2', 'transaction_id' => $transaction->id];

        $this->postJson('/api/make-payment', $payload)->assertOk();
        $this->postJson('/api/make-payment', $payload)->assertStatus(409);

        $this->assertSame(1, Payment::where('payment_id', 'P-2')->count());
        $this->assertSame(9, $product->fresh()->quantity);
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
