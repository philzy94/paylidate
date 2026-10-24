<?php

namespace Tests\Unit\Services;

use App\Services\FlutterwaveService;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class FlutterwaveServiceTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        config(['services.flutterwave' => [
            'public_key' => 'FLWPUBK-test',
            'secret_key' => 'FLWSECK-testsecretkey12345',
            'base_url' => 'https://flw.test',
            'rave_base_url' => 'https://rave.test',
        ]]);
    }

    public function testBanksUsesBearerAuthAndNigeriaEndpoint()
    {
        Http::fake(['flw.test/*' => Http::response(['status' => 'success', 'data' => [['code' => '044']]])]);

        $response = (new FlutterwaveService())->banks();

        $this->assertSame('044', $response['data'][0]['code']);
        Http::assertSent(function (Request $request) {
            return $request->url() === 'https://flw.test/v3/banks/NG'
                && $request->hasHeader('Authorization', 'Bearer FLWSECK-testsecretkey12345');
        });
    }

    public function testGetRateSendsQueryParameters()
    {
        Http::fake(['flw.test/*' => Http::response(['status' => 'success'])]);

        (new FlutterwaveService())->getRate(100, 'NGN', 'USD');

        Http::assertSent(function (Request $request) {
            return str_starts_with($request->url(), 'https://flw.test/v3/transfers/rates')
                && $request['amount'] == 100
                && $request['destination_currency'] === 'NGN'
                && $request['source_currency'] === 'USD';
        });
    }

    public function testVerifyBankAccountNumberPostsAccountDetails()
    {
        Http::fake(['flw.test/*' => Http::response(['status' => 'success'])]);

        (new FlutterwaveService())->verifyBankAccountNumber('0690000031', '044');

        Http::assertSent(function (Request $request) {
            return $request->url() === 'https://flw.test/v3/accounts/resolve'
                && $request['account_number'] === '0690000031'
                && $request['account_bank'] === '044';
        });
    }

    public function testCreateVirtualAccountReturnsDecodedObject()
    {
        Http::fake(['flw.test/*' => Http::response([
            'status' => 'success',
            'data' => ['order_ref' => 'URF_1', 'account_number' => '7824822527', 'bank_name' => 'WEMA BANK'],
        ])]);

        $response = (new FlutterwaveService())->createVirtualAccount('a@b.test', 'Ada', 'PD_1', '22222222222');

        $this->assertSame('success', $response->status);
        $this->assertSame('7824822527', $response->data->account_number);
        Http::assertSent(fn (Request $request) => $request['bvn'] === '22222222222'
            && $request['is_permanent'] === true
            && $request['tx_ref'] === 'PD_1');
    }

    public function testCreateVirtualAccountReturnsErrorObjectWhenTheRequestThrows()
    {
        Http::fake(fn () => throw new \RuntimeException('network down'));

        $response = (new FlutterwaveService())->createVirtualAccount('a@b.test', 'Ada', 'PD_1', '1');

        $this->assertSame('error', $response->status);
    }

    public function testVerifyPaymentReturnsDecodedArray()
    {
        Http::fake(['rave.test/*' => Http::response(['status' => 'success', 'data' => ['status' => 'successful']])]);

        $response = (new FlutterwaveService())->verify_payment('PD_REF');

        $this->assertSame('successful', $response['data']['status']);
        Http::assertSent(fn (Request $request) => $request['txref'] === 'PD_REF'
            && $request['SECKEY'] === 'FLWSECK-testsecretkey12345');
    }

    public function testValidatePaymentSendsOtpAndReference()
    {
        Http::fake(['rave.test/*' => Http::response(['status' => 'success'])]);

        (new FlutterwaveService())->validate_payment('FLW-REF', '12345');

        Http::assertSent(fn (Request $request) => $request['transaction_reference'] === 'FLW-REF'
            && $request['otp'] === '12345'
            && $request['PBFPubKey'] === 'FLWPUBK-test');
    }

    public function testPayViaCardEncryptsThePayload()
    {
        Http::fake(['rave.test/*' => Http::response(['status' => 'success', 'data' => ['authurl' => 'https://auth.test']])]);

        $response = (new FlutterwaveService())->payviacard(['cardno' => '5531886652142950', 'amount' => 100]);

        $this->assertSame('https://auth.test', $response['data']['authurl']);
        Http::assertSent(function (Request $request) {
            return $request['alg'] === '3DES-24'
                && ! str_contains($request['client'], '5531886652142950')
                && $request['PBFPubKey'] === 'FLWPUBK-test';
        });
    }

    public function testTransferToBankPostsNgnTransfer()
    {
        Http::fake(['flw.test/*' => Http::response(['status' => 'success'])]);

        (new FlutterwaveService())->transfer_to_bank([
            'account_bank' => '044',
            'account_number' => '0690000031',
            'amount' => 5000,
            'narration' => 'Payout',
        ]);

        Http::assertSent(function (Request $request) {
            return $request->url() === 'https://flw.test/v3/transfers'
                && $request['currency'] === 'NGN'
                && $request['amount'] == 5000
                && str_starts_with($request['reference'], 'PD_');
        });
    }

    public function testGetTransactionVerifiesById()
    {
        Http::fake(['flw.test/*' => Http::response(['status' => 'success'])]);

        (new FlutterwaveService())->getTransaction(998877);

        Http::assertSent(fn (Request $request) => $request->url() === 'https://flw.test/v3/transactions/998877/verify/');
    }

    public function testVirtualCardEndpointsUseTheCardIdAndBearerAuth()
    {
        Http::fake(['flw.test/*' => Http::response(['status' => 'success'])]);
        $service = new FlutterwaveService();

        $service->withdrawFromVirtualCard('card-1', 500);
        $service->fundVirtualCard('card-1', 700);

        Http::assertSent(fn (Request $request) => $request->url() === 'https://flw.test/v3/virtual-cards/card-1/withdraw'
            && $request['amount'] === 500
            && $request->hasHeader('Authorization', 'Bearer FLWSECK-testsecretkey12345'));
        Http::assertSent(fn (Request $request) => $request->url() === 'https://flw.test/v3/virtual-cards/card-1/fund'
            && $request['amount'] === 700
            && $request['debit_currency'] === 'NGN');
    }

    public function testPaymentLinkPostsTheCustomerAndRedirect()
    {
        Http::fake(['flw.test/*' => Http::response(['status' => 'success', 'data' => ['link' => 'https://pay.test/x']])]);

        $response = (new FlutterwaveService())->getPaymentLink(
            'Ada',
            1500,
            'NGN',
            'https://app.test/done',
            ['order' => 1],
            ['email' => 'ada@test.test'],
            ['title' => 'Paylidate']
        );

        $this->assertSame('https://pay.test/x', $response['data']['link']);
        Http::assertSent(fn (Request $request) => $request->url() === 'https://flw.test/v3/payments'
            && $request['amount'] === 1500
            && $request['redirect_url'] === 'https://app.test/done'
            && $request['customer']['email'] === 'ada@test.test'
            && str_starts_with($request['tx_ref'], 'Ada-tx-'));
    }

    public function testVirtualAccountLookupUsesTheOrderReference()
    {
        Http::fake(['flw.test/*' => Http::response(['status' => 'success'])]);

        (new FlutterwaveService())->getVirtualAccount('URF_123');

        Http::assertSent(fn (Request $request) => $request->url() === 'https://flw.test/v3/virtual-account-numbers/URF_123');
    }
}
