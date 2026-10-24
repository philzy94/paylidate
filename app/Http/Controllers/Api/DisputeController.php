<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\DisputeService;
use Illuminate\Http\Request;

class DisputeController extends Controller
{
    protected $disputes;

    public function __construct(DisputeService $disputes)
    {
        $this->disputes = $disputes;
    }

    public function getTransactionDisputes($transaction_id)
    {
        return $this->success($this->disputes->forTransaction(auth('api')->user(), (int) $transaction_id));
    }

    public function open_dispute(Request $request)
    {
        $data = $request->validate([
            'transaction_id' => 'required|integer',
            'subject' => 'required|string',
            'description' => 'required|string',
        ]);

        return $this->success($this->disputes->open(
            auth('api')->user(),
            (int) $data['transaction_id'],
            $data['subject'],
            $data['description']
        ));
    }

    public function resolve_dispute(Request $request)
    {
        $data = $request->validate(['id' => 'required|integer']);

        return $this->success($this->disputes->resolve(auth('api')->user(), (int) $data['id']));
    }
}
