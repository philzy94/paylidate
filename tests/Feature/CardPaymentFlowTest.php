<?php

namespace Tests\Feature;

use App\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Laravel\Passport\Passport;
use Tests\TestCase;

class CardPaymentFlowTest extends TestCase
{
    use RefreshDatabase;

    /** @var array<string, string> */
    private array $card = [
        'cardno' => '5531886652142950', 'cvv' => '564', 'expirymonth' => '09', 'expiryyear' => '32',
        'currency' => 'NGN', 'country' => 'NG', 'amount' => '100', 'pin' => '3310',
    ];

    protected function setUp(): void
    {
        parent::setUp();

        config(['services.flutterwave' => [
            'public_key' => 'test-public-key',
            'secret_key' => 'test-secret-key',
            'base_url' => 'https://flw.test',
            'rave_base_url' => 'https://rave.test',
        ]]);
        Passport::actingAs(User::factory()->create(['name' => 'Ada Lovelace']), [], 'api');
    }

    public function testAFailedChargeIsReportedAsAnInitiationFailure()
    {
        Http::fake(['rave.test/*' => Http::response(['status' => 'error', 'data' => ['code' => 'FLW_ERR']])]);

        $this->postJson('/api/payment/card', $this->card)
            ->assertStatus(417)
            ->assertJsonPath('code', 'payment_initiation_failed');
    }

    public function testAPinChallengeIsRetriedWithPinAuthorisation()
    {
        Http::fake(['rave.test/*' => Http::sequence()
            ->push(['status' => 'success', 'data' => ['authurl' => 'N/A', 'suggested_auth' => 'PIN']])
            ->push(['status' => 'success', 'data' => ['flwRef' => 'FLW-REF', 'chargeResponseCode' => '02']])]);

        $this->postJson('/api/payment/card', $this->card)
            ->assertOk()
            ->assertJsonPath('data.flwRef', 'FLW-REF');

        Http::assertSentCount(2);
    }

    public function testInternationalCardsWithoutAuthAreRefused()
    {
        Http::fake(['rave.test/*' => Http::response([
            'status' => 'success',
            'data' => ['authurl' => 'N/A', 'suggested_auth' => 'NOAUTH_INTERNATIONAL'],
        ])]);

        $this->postJson('/api/payment/card', $this->card)
            ->assertStatus(422)
            ->assertJsonPath('code', 'card_not_chargeable');
    }

    public function testOtherChallengesAreReturnedToTheClient()
    {
        Http::fake(['rave.test/*' => Http::response([
            'status' => 'success',
            'data' => ['authurl' => 'N/A', 'suggested_auth' => 'OTP', 'flwRef' => 'FLW-OTP'],
        ])]);

        $this->postJson('/api/payment/card', $this->card)
            ->assertOk()
            ->assertJsonPath('data.suggested_auth', 'OTP');
    }

    public function testValidatePaymentSendsTheOtpToFlutterwave()
    {
        Http::fake(['rave.test/*' => Http::response(['status' => 'success', 'data' => ['status' => 'successful']])]);

        $this->postJson('/api/payment/validate', ['flwRef' => 'FLW-REF', 'otp' => '12345'])
            ->assertOk()
            ->assertJsonPath('data.status', 'successful');

        Http::assertSent(fn (Request $request) => str_ends_with($request->url(), '/validatecharge')
            && $request['transaction_reference'] === 'FLW-REF'
            && $request['otp'] === '12345');
    }

    public function testValidatePaymentRequiresBothFields()
    {
        $this->postJson('/api/payment/validate', ['flwRef' => 'FLW-REF'])->assertStatus(422);
    }

    public function testVerifyPaymentLooksUpTheTransactionReference()
    {
        Http::fake(['rave.test/*' => Http::response(['status' => 'success', 'data' => ['status' => 'successful', 'amount' => 100]])]);

        $this->postJson('/api/payment/verify', ['flw_T_id' => 'PD_TX'])
            ->assertOk()
            ->assertJsonPath('data.amount', 100);

        Http::assertSent(fn (Request $request) => $request['txref'] === 'PD_TX' && $request['SECKEY'] === 'test-secret-key');
    }

    public function testRateAndBanksAreProxiedFromFlutterwave()
    {
        Http::fake([
            'flw.test/v3/transfers/rates*' => Http::response(['status' => 'success', 'data' => ['rate' => 1500]]),
            'flw.test/v3/banks/NG' => Http::response(['status' => 'success', 'data' => [['code' => '044', 'name' => 'Access']]]),
        ]);

        $this->postJson('/api/get-rate', ['amount' => 1, 'destination_currency' => 'NGN', 'source_currency' => 'USD'])
            ->assertOk()
            ->assertJsonPath('data.rate', 1500);
        $this->getJson('/api/get-banks')
            ->assertOk()
            ->assertJsonPath('data.0.code', '044');
    }

    public function testRateRequiresItsParameters()
    {
        $this->postJson('/api/get-rate', ['amount' => 'abc'])->assertStatus(422);
    }
}
