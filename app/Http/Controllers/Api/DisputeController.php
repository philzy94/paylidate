<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\DisputeService;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;

class DisputeController extends Controller
{
    protected DisputeService $disputes;

    public function __construct(DisputeService $disputes)
    {
        $this->disputes = $disputes;
    }

    public function getTransactionDisputes(string $transaction_id): JsonResponse
    {
        return $this->success($this->disputes->forTransaction($this->authUser(), (int) $transaction_id));
    }

    public function open_dispute(Request $request): JsonResponse
    {
        $data = $request->validate([
            'transaction_id' => 'required|integer',
            'subject' => 'required|string',
            'description' => 'required|string',
        ]);

        return $this->success($this->disputes->open(
            $this->authUser(),
            (int) $data['transaction_id'],
            $data['subject'],
            $data['description']
        ));
    }

    public function resolve_dispute(Request $request): JsonResponse
    {
        $data = $request->validate(['id' => 'required|integer']);

        return $this->success($this->disputes->resolve($this->authUser(), (int) $data['id']));
    }
}
