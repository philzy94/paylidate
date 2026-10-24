<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\PayoutService;
use Illuminate\Http\Request;

class ReferralWidrawalController extends Controller
{
    protected $payouts;

    public function __construct(PayoutService $payouts)
    {
        $this->payouts = $payouts;
    }

    public function index()
    {
        return $this->success($this->payouts->referralWithdrawals(auth('api')->user()));
    }

    public function request_withdrawal(Request $request)
    {
        $data = $request->validate([
            'narration' => 'nullable|string',
            'debit_currency' => 'nullable|string',
        ]);

        return $this->success($this->payouts->requestReferralWithdrawal(auth('api')->user(), $data));
    }

    /**
     * Staff only (see the `admin` middleware on the route).
     */
    public function transfer_to_bank(Request $request)
    {
        $data = $request->validate(['id' => 'required|numeric']);

        return response()->json([
            'status' => 'success',
            'message' => 'Transfer queued',
            'data' => $this->payouts->transferReferralWithdrawal((int) $data['id']),
        ]);
    }
}
