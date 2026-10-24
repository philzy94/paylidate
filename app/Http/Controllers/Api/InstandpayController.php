<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\InstantPayService;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;

class InstandpayController extends Controller
{
    protected InstantPayService $instantPay;

    public function __construct(InstantPayService $instantPay)
    {
        $this->instantPay = $instantPay;
    }

    public function receive(): JsonResponse
    {
        return $this->success($this->instantPay->received($this->authUser()));
    }

    public function send(): JsonResponse
    {
        return $this->success($this->instantPay->sent($this->authUser()));
    }

    public function verify_user(string $phone_number): JsonResponse
    {
        $name = $this->instantPay->receiverName($this->authUser(), $phone_number);

        return response()->json(['status' => 'success', 'message' => 'success', 'name' => $name]);
    }

    public function transfer(Request $request): JsonResponse
    {
        $data = $request->validate([
            'receiver_number' => 'required|digits:11',
            'payment_ref' => 'required|string',
            'description' => 'nullable|string',
        ]);

        return $this->success($this->instantPay->send(
            $this->authUser(),
            $data['receiver_number'],
            $data['payment_ref'],
            $data['description'] ?? null
        ));
    }

    // Verify the PIN and register the bank account to pay out to
    public function verify(Request $request): JsonResponse
    {
        $data = $request->validate([
            'withdrawal_pin' => 'required|numeric',
            'link_token' => 'required|string',
            'bank_code' => 'required|numeric',
            'account_number' => 'required|numeric',
        ]);

        return $this->success($this->instantPay->claim(
            $this->authUser(),
            $data['link_token'],
            (string) $data['withdrawal_pin'],
            (string) $data['bank_code'],
            (string) $data['account_number']
        ));
    }

    public function withdraw(Request $request): JsonResponse
    {
        $data = $request->validate(['id' => 'required|numeric']);

        return $this->success($this->instantPay->withdraw($this->authUser(), (int) $data['id']), 'Transfer successful');
    }
}
