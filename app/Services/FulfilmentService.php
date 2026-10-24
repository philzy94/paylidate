<?php

namespace App\Services;

use App\Fulfillment;
use App\Mail\FulfilmentMail;
use App\Mail\SellerFulfilmentMail;
use Illuminate\Support\Facades\Mail;

class FulfilmentService
{
    public function initiate_fufilment($seller_mail, $seller_name, $buyer_mail, $buyer_name, $buyer_id, $t_id, $t_ref, $transaction_details)
    {
        try {
            $code = $this->generateCode();

            Fulfillment::create([
                'user_id' => $buyer_id,
                'transaction_id' => $t_id,
                'transaction_ref' => $t_ref,
                'code' => $code,
                'status' => Fulfillment::PENDING
            ]);

            Mail::to($buyer_mail)->send(new FulfilmentMail($buyer_name, $code, $transaction_details));
            Mail::to($seller_mail)->send(new SellerFulfilmentMail($seller_name, $this->url($buyer_id, $t_id), $transaction_details));

            return true;
        } catch (\Throwable $exception) {
            report($exception);
            throw $exception;
        }
    }

    protected function generateCode(): int
    {
        do {
            $number = mt_rand(10000, 99999);
        } while (Fulfillment::where('code', $number)->exists());

        return $number;
    }

    protected function url($buyer_id, $trx_id): string
    {
        return 'paylidate.com/fulfillment/' . base64_encode($buyer_id . ':' . $trx_id);
    }
}
