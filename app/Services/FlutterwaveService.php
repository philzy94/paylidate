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

}