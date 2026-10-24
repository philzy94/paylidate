<?php

namespace App\Services;

use Illuminate\Http\Client\Response;
use Illuminate\Support\Str;
use Illuminate\Support\Facades\Http;

class FlutterwaveService
{
    public function virtualCard(string $currency, int|float|string $amount, string $name): Response
    {
        $response = Http::withHeaders([
            'Authorization' => 'Bearer ' . config('services.flutterwave.secret_key')
        ])->post(config('services.flutterwave.base_url') . '/v3/virtual-cards', [
            "currency" => $currency,
            "amount" => $amount,
            "billing_name" => $name
        ]);

        return $response;
    }


    public function getvirtualCard(int|string $card_id): Response
    {
        $response = Http::withHeaders([
            'Authorization' => 'Bearer ' . config('services.flutterwave.secret_key')
        ])->get(config('services.flutterwave.base_url') . '/v3/virtual-cards');

        return $response;
    }

    public function withdrawFromVirtualCard(int|string $card_id, int|float|string $amount): Response
    {
        $response = Http::withHeaders([
            'Authorization' => 'Bearer ' . config('services.flutterwave.secret_key')
        ])->post(config('services.flutterwave.base_url') . '/v3/virtual-cards/' . $card_id . '/withdraw', [
            "amount" => $amount,
        ]);

        return $response;
    }

    // fund virtual card with payment
    public function fundVirtualCard(int|string $card_id, int|float|string $amount, string $debit_currency = 'NGN'): Response
    {
        $response = Http::withHeaders([
            'Authorization' => 'Bearer ' . config('services.flutterwave.secret_key')
        ])->post(config('services.flutterwave.base_url') . '/v3/virtual-cards/' . $card_id . '/fund', [
            "amount" => $amount,
            "debit_currency" => $debit_currency,
        ]);

        return $response;
    }



    /**
     * @param array<string, mixed> $meta
     * @param array<string, mixed> $customer
     * @param array<string, mixed> $customizations
     */
    public function getPaymentLink(
        string $name,
        int|float|string $amount,
        string $currency,
        string $redirect_url,
        array $meta,
        array $customer,
        array $customizations
    ): Response {

        $response = Http::withHeaders([
            'Authorization' => 'Bearer ' . config('services.flutterwave.secret_key')
        ])->post(config('services.flutterwave.base_url') . '/v3/payments', [
            "tx_ref" => $name . "-tx-" . time(),
            "amount" => $amount,
            "currency" => $currency,
            "redirect_url" => $redirect_url,
            "payment_options" => "card",
            "meta" => $meta,
            "customer" => $customer,
            "customizations" => $customizations
        ]);

        return $response;
    }


    public function getRate(int|float|string $amount, string $destination_currency, string $source_currency): Response
    {
        $response = Http::withHeaders([
            'Authorization' => 'Bearer ' . config('services.flutterwave.secret_key')
        ])->get(config('services.flutterwave.base_url') . '/v3/transfers/rates', [
            'amount' => $amount,
            'destination_currency' => $destination_currency,
            'source_currency' => $source_currency
        ]);

        return $response;
    }

    public function banks(): Response
    {
        $response = Http::withHeaders([
            'Authorization' => 'Bearer ' . config('services.flutterwave.secret_key')
        ])->get(config('services.flutterwave.base_url') . '/v3/banks/NG');

        return $response;
    }

    public function verifyBankAccountNumber(string $account_num, string $bank_code): Response
    {
        $response = Http::withHeaders([
            'Authorization' => 'Bearer ' . config('services.flutterwave.secret_key')
        ])->post(config('services.flutterwave.base_url') . '/v3/accounts/resolve', [
            "account_number" => $account_num,
            "account_bank" => $bank_code,

        ]);

        return $response;
    }

    /**
     * @return \stdClass Decoded provider response, or an error object when the call fails.
     */
    public function createVirtualAccount(string $email, string $name, string $tx_ref, string $bvn): \stdClass
    {
        try {
            $response = Http::withHeaders([
                'Authorization' => 'Bearer ' . config('services.flutterwave.secret_key'),
            ])->post(config('services.flutterwave.base_url') . '/v3/virtual-account-numbers', [
                'email' => $email,
                'is_permanent' => true,
                'bvn' => $bvn,
                'tx_ref' => $tx_ref,
                'narration' => $name,
            ]);

            $decoded = json_decode($response->body());

            if (!$decoded instanceof \stdClass) {
                return (object) ['status' => 'error', 'message' => 'An error occured. Please try again'];
            }

            return $decoded;
        } catch (\Exception $e) {
            report($e);

            return (object) ['status' => 'error', 'message' => 'An error occured. Please try again'];
        }
    }

    public function getVirtualAccount(string $order_ref): Response
    {
        $response = Http::withHeaders([
            'Authorization' => 'Bearer ' . config('services.flutterwave.secret_key')
        ])->post(config('services.flutterwave.base_url') . '/v3/virtual-account-numbers/' . $order_ref);

        return $response;
    }

    public function getTransaction(int|string $transaction_id): Response
    {
        $response = Http::withHeaders([
            'Authorization' => 'Bearer ' . config('services.flutterwave.secret_key')
        ])->get(config('services.flutterwave.base_url') . '/v3/transactions/' . $transaction_id . '/verify/');

        return $response;
    }


    protected function getKey(string $seckey): string
    {
        $hashedkey = md5($seckey);
        $hashedkeylast12 = substr($hashedkey, -12);

        $seckeyadjusted = str_replace("FLWSECK-", "", $seckey);
        $seckeyadjustedfirst12 = substr($seckeyadjusted, 0, 12);

        $encryptionkey = $seckeyadjustedfirst12 . $hashedkeylast12;
        return $encryptionkey;
    }

    protected function encrypt3Des(string $data, string $key): string
    {
        $encData = openssl_encrypt($data, 'DES-EDE3', $key, OPENSSL_RAW_DATA);

        return base64_encode($encData === false ? '' : $encData);
    }

    /**
     * @param array<string, mixed> $data
     * @return array<string, mixed>|null
     */
    public function payviacard(array $data): ?array
    {

        $SecKey = (string) config('services.flutterwave.secret_key');

        $key = $this->getKey($SecKey);

        $dataReq = json_encode($data, JSON_THROW_ON_ERROR);

        $post_enc = $this->encrypt3Des($dataReq, $key);

        $response = Http::withHeaders([
            "Content-Type" => "application/json"
        ])->post(config('services.flutterwave.rave_base_url') . '/flwv3-pug/getpaidx/api/charge', [
            'PBFPubKey' => config('services.flutterwave.public_key'),
            'client' => $post_enc,
            'alg' => '3DES-24'
        ]);

        $response = $response->json();

        return $response;
    }

    /**
     * @return array<string, mixed>|null
     */
    public function validate_payment(string $flwRef, string $otp): ?array
    {

        $response = Http::withHeaders([
            "Content-Type" => "application/json"
        ])->post(config('services.flutterwave.rave_base_url') . '/flwv3-pug/getpaidx/api/validatecharge', [
            "PBFPubKey" => config('services.flutterwave.public_key'),
            "transaction_reference" => $flwRef,
            "otp" => $otp
        ]);


        $response = $response->json();

        return $response;
    }

    /**
     * @return array<string, mixed>|null
     */
    public function verify_payment(string $txRef): ?array
    {
        $response = Http::withHeaders([
            "Content-Type" => "application/json"
        ])->post(config('services.flutterwave.rave_base_url') . "/flwv3-pug/getpaidx/api/v2/verify", [
            'SECKEY' => config('services.flutterwave.secret_key'),
            "txref" => $txRef,
        ]);


        $response = $response->json();

        return $response;
    }

    /**
     * @param array<string, mixed> $param
     * @return Response|array<string, string>
     */
    public function transfer_to_bank(array $param): Response|array
    {
        try {
            $response = Http::withHeaders([
                'Authorization' => 'Bearer ' . config('services.flutterwave.secret_key')
            ])->post(config('services.flutterwave.base_url') . '/v3/transfers', [

                "account_bank" => $param["account_bank"],
                "account_number" => $param["account_number"],
                "amount" => $param["amount"],
                "narration" => $param["narration"],
                "currency" => "NGN",
                "reference" => 'PD_' . Str::random(8) . date('dmyHis'),
                "debit_currency" => "NGN"
            ]);

            return $response;
        } catch (\Exception $e) {
            report($e);
            return [
                'status' => 'error',
                'message' => 'An error occured. Please try again',
            ];
        }
    }
}
