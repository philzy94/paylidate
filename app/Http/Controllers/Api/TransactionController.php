<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\TransactionService;
use Illuminate\Http\Request;

/**
 * @group  Transaction management
 *
 * APIs for Transaction
 */
class TransactionController extends Controller
{
    protected $transactions;

    public function __construct(TransactionService $transactions)
    {
        $this->transactions = $transactions;
    }

    public function index()
    {
        return $this->success($this->transactions->listFor(auth('api')->user()));
    }

    public function store(Request $request)
    {
        $data = $request->validate([
            'product_id' => 'required|integer',
            'quantity' => 'required|integer|min:1',
            'referral_token' => 'nullable|string',
            'description' => 'nullable|string',
        ]);

        $user = auth('api')->user();
        $transaction = $this->transactions->create($user, $data);
        $this->transactions->notifyCreated($transaction, $user, true);

        return $this->success($transaction);
    }

    // get individual transaction
    public function get_transaction($T_ref)
    {
        return $this->success($this->transactions->details($T_ref, auth('api')->user()));
    }

    public function accept($id)
    {
        return $this->success($this->transactions->setAcceptance($id, auth('api')->user(), true));
    }

    public function decline($id)
    {
        return $this->success($this->transactions->setAcceptance($id, auth('api')->user(), false));
    }

    // confirm transaction completion
    public function confirm($id)
    {
        return $this->success($this->transactions->confirm($id, auth('api')->user()));
    }

    public function reportTransaction($id, Request $request)
    {
        $data = $request->validate([
            'sellerEmail' => 'required|string|email',
            'report' => 'required|string|min:5',
        ]);

        return $this->success(
            $this->transactions->report($id, auth('api')->user(), $data['sellerEmail'], $data['report'])
        );
    }

    public function resloveReport($id, $sellerEmail)
    {
        return $this->success($this->transactions->resolveReport($id, auth('api')->user(), $sellerEmail));
    }

    public function cancel($id)
    {
        return $this->success($this->transactions->cancel($id, auth('api')->user()));
    }
}
