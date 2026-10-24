<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\PayoutService;
use Illuminate\Http\Request;

class RefundController extends Controller
{
    protected $payouts;

    public function __construct(PayoutService $payouts)
    {
        $this->payouts = $payouts;
    }

    public function index()
    {
        return $this->success($this->payouts->refunds(auth('api')->user()));
    }

    public function request_Refund(Request $request)
    {
        $data = $request->validate([
            'transaction_id' => 'required|numeric',
            'payment_id' => 'required|numeric',
            'bank_id' => 'required|numeric',
            'narration' => 'required|string',
            'debit_currency' => 'required|string',
        ]);

        return $this->success($this->payouts->requestRefund(auth('api')->user(), $data));
    }

    /**
     * Staff only (see the `admin` middleware on the route).
     */
    public function transfer_to_bank(Request $request)
    {
        $data = $request->validate(['id' => 'required|numeric']);

        $result = $this->payouts->transferRefund((int) $data['id']);

        return response()->json([
            'status' => 'success',
            'message' => 'Transfer queued',
            'data' => $result,
        ]);
    }
}
