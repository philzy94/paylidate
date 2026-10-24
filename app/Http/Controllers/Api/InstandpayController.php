<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\InstantPayService;
use Illuminate\Http\Request;

class InstandpayController extends Controller
{
    protected $instantPay;

    public function __construct(InstantPayService $instantPay)
    {
        $this->instantPay = $instantPay;
    }

    public function receive()
    {
        return $this->success($this->instantPay->received(auth('api')->user()));
    }

    public function send()
    {
        return $this->success($this->instantPay->sent(auth('api')->user()));
    }

    public function verify_user($phone_number)
    {
        $name = $this->instantPay->receiverName(auth('api')->user(), $phone_number);

        return response()->json(['status' => 'success', 'message' => 'success', 'name' => $name]);
    }

    public function transfer(Request $request)
    {
        $data = $request->validate([
            'receiver_number' => 'required|digits:11',
            'payment_ref' => 'required|string',
            'description' => 'nullable|string',
        ]);

        return $this->success($this->instantPay->send(
            auth('api')->user(),
            $data['receiver_number'],
            $data['payment_ref'],
            $data['description'] ?? null
        ));
    }

    // Verify the PIN and register the bank account to pay out to
    public function verify(Request $request)
    {
        $data = $request->validate([
            'withdrawal_pin' => 'required|numeric',
            'link_token' => 'required|string',
            'bank_code' => 'required|numeric',
            'account_number' => 'required|numeric',
        ]);

        return $this->success($this->instantPay->claim(
            auth('api')->user(),
            $data['link_token'],
            (string) $data['withdrawal_pin'],
            (string) $data['bank_code'],
            (string) $data['account_number']
        ));
    }

    public function withdraw(Request $request)
    {
        $data = $request->validate(['id' => 'required|numeric']);

        return $this->success($this->instantPay->withdraw(auth('api')->user(), (int) $data['id']), 'Transfer successful');
    }
}
