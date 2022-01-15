<?php

namespace App\Services;

use App\Mail\RemindVerifyEmail;
use App\User;
use Mail;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Http;

class FlutterwaveService
{

    public function __construct(){

    }

    public function virtualCard($currency, $amount, $name){
        $response = Http::withHeaders([
            'Authorization' => 'Bearer '.env('FLW_SECRET_KEY')
        ])->post(env('FLW_BASE_URL').'/v3/virtual-cards', [
            "currency" => $currency,
            "amount" => $amount,
            "billing_name" => $name
        ]);

        return $response;
    }

    
    public function getvirtualCard($card_id){
        $response = Http::withHeaders([
            'Authorization' => 'Bearer '.env('FLW_SECRET_KEY')
        ])->get(env('FLW_BASE_URL').'/v3/virtual-cards/'.$card_id);

        return $response;
    }

    public function withdrawFromVirtualCard($card_id, $amount){
        $response = Http::withHeaders([
            'Authorization' => 'Bearer '.env('FLW_SECRET_KEY')
        ])->post(env('FLW_BASE_URL').'/v3/virtual-cards/'. $card_id .'/withdraw', [
            "amount" => $amount,
        ]);

        return $response;
    }

    // fund virtual card with payment
    public function fundVirtualCard($card_id, $amount, $debit_currency = 'NGN'){
        $response = Http::withHeaders([
            'Authorization' => 'Bearer '.env('FLW_SECRET_KEY')
        ])->post(env('FLW_BASE_URL').'/v3/virtual-cards/'. $card_id .'/fund', [
            "amount" => $amount,
            "debit_currency" => $debit_currency,
        ]);

        return $response;
    }



    public function getPaymentLink($name, $amount, $currency, $redirect_url, $meta, $customer, $customizations){
       
        $response = Http::withHeaders([
            'Authorization' => 'Bearer '.env('FLW_SECRET_KEY')
        ])->post(env('FLW_BASE_URL').'/v3/payments', [
            "tx_ref" => $name."-tx-".time(),
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


    public function getRate($amount, $destination_currency, $source_currency){
        $response = Http::withHeaders([
            'Authorization' => 'Bearer '.env('FLW_SECRET_KEY')
            ])->get(env('FLW_BASE_URL').'/v3/transfers/rates', [
                'amount' => $amount,
                'destination_currency' => $destination_currency,
                'source_currency' => $source_currency
            ]);

        return $response;
    }

    public function banks(){
        $response = Http::withHeaders([
            'Authorization' => 'Bearer '.env('FLW_SECRET_KEY')
            ])->get(env('FLW_BASE_URL').'/v3/banks/NG');

        return $response;
    }

    public function createVirtualAccount($email, $is_permanent, $name){
        $response = Http::withHeaders([
            'Authorization' => 'Bearer '.env('FLW_SECRET_KEY')
        ])->post(env('FLW_BASE_URL').'/v3/virtual-account-numbers', [
            "email" => $email,
            "is_permanent" => $is_permanent,
            "tx_ref" => $name.'-'.time(),
            "narration" => $name,
        ]);

        return $response;
    }

}