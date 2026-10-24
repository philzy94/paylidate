<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\PayoutService;
use App\Services\PayoutTransferService;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;

class ReferralWidrawalController extends Controller
{
    protected PayoutService $payouts;

    public function __construct(PayoutService $payouts)
    {
        $this->payouts = $payouts;
    }

    public function index(): JsonResponse
    {
        return $this->success($this->payouts->referralWithdrawals($this->authUser()));
    }

    public function request_withdrawal(Request $request): JsonResponse
    {
        $data = $request->validate([
            'narration' => 'nullable|string',
            'debit_currency' => 'nullable|string',
        ]);

        return $this->success($this->payouts->requestReferralWithdrawal($this->authUser(), $data));
    }

    /**
     * Staff only (see the `admin` middleware on the route).
     */
    public function transfer_to_bank(Request $request, PayoutTransferService $transfers): JsonResponse
    {
        $data = $request->validate(['id' => 'required|numeric']);

        return response()->json([
            'status' => 'success',
            'message' => 'Transfer queued',
            'data' => $transfers->transferReferralWithdrawal((int) $data['id']),
        ]);
    }
}
