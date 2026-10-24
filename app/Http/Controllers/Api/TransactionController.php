<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\TransactionService;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;

/**
 * @group  Transaction management
 *
 * APIs for Transaction
 */
class TransactionController extends Controller
{
    protected TransactionService $transactions;

    public function __construct(TransactionService $transactions)
    {
        $this->transactions = $transactions;
    }

    public function index(): JsonResponse
    {
        return $this->success($this->transactions->listFor($this->authUser()));
    }

    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'product_id' => 'required|integer',
            'quantity' => 'required|integer|min:1',
            'referral_token' => 'nullable|string',
            'description' => 'nullable|string',
        ]);

        $user = $this->authUser();
        $transaction = $this->transactions->create($user, $data);
        $this->transactions->notifyCreated($transaction, $user, true);

        return $this->success($transaction);
    }

    // get individual transaction
    public function get_transaction(string $T_ref): JsonResponse
    {
        return $this->success($this->transactions->details($T_ref, $this->optionalUser()));
    }

    public function accept(string $id): JsonResponse
    {
        return $this->success($this->transactions->setAcceptance((int) $id, $this->authUser(), true));
    }

    public function decline(string $id): JsonResponse
    {
        return $this->success($this->transactions->setAcceptance((int) $id, $this->authUser(), false));
    }

    // confirm transaction completion
    public function confirm(string $id): JsonResponse
    {
        return $this->success($this->transactions->confirm((int) $id, $this->authUser()));
    }

    public function reportTransaction(string $id, Request $request): JsonResponse
    {
        $data = $request->validate([
            'sellerEmail' => 'required|string|email',
            'report' => 'required|string|min:5',
        ]);

        return $this->success(
            $this->transactions->report((int) $id, $this->authUser(), $data['sellerEmail'], $data['report'])
        );
    }

    public function resloveReport(string $id, string $sellerEmail): JsonResponse
    {
        return $this->success($this->transactions->resolveReport((int) $id, $this->authUser(), $sellerEmail));
    }

    public function cancel(string $id): JsonResponse
    {
        return $this->success($this->transactions->cancel((int) $id, $this->authUser()));
    }
}
