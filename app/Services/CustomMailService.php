<?php

namespace App\Services;

use App\Mail\WalletCreated;
use Illuminate\Support\Facades\Mail;

class CustomMailService
{
    public function sendMail($to, $data)
    {
        try {
            Mail::to($to)->send(new WalletCreated($data));

            return [
                'status' => 'success',
                'message' => 'mail sent successfully'
            ];
        } catch (\Exception $e) {
            report($e);
            return [
            'status' => 'error',
            'message' => 'An error occured. Please try again'
            ];
        }
    }
}
