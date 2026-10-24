<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\PayoutService;
use App\Services\PayoutTransferService;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;

class WithdrawalController extends Controller
{
    protected PayoutService $payouts;

    public function __construct(PayoutService $payouts)
    {
        $this->payouts = $payouts;
    }

    public function index(): JsonResponse
    {
        return $this->success($this->payouts->withdrawals($this->authUser()));
    }

    public function request_withdrawal(Request $request): JsonResponse
    {
        $data = $request->validate([
            'transaction_id' => 'required|numeric',
            'payment_id' => 'required|numeric',
            'bank_id' => 'required|numeric',
            'narration' => 'required|string',
            'debit_currency' => 'required|string',
        ]);

        return $this->success($this->payouts->requestWithdrawal($this->authUser(), $data));
    }

    /**
     * Staff only (see the `admin` middleware on the route).
     */
    public function transfer_to_bank(Request $request, PayoutTransferService $transfers): JsonResponse
    {
        $data = $request->validate(['id' => 'required|numeric']);

        $result = $transfers->transferWithdrawal((int) $data['id']);

        return response()->json([
            'status' => 'success',
            'message' => 'Transfer queued',
            'data' => $result,
        ]);
    }
}
