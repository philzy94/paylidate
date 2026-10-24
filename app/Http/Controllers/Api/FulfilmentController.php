<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\FulfilmentService;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;

class FulfilmentController extends Controller
{
    protected FulfilmentService $fulfilments;

    public function __construct(FulfilmentService $fulfilments)
    {
        $this->fulfilments = $fulfilments;
    }

    public function get_transaction(string $hash): JsonResponse
    {
        return $this->success($this->fulfilments->details($hash));
    }

    public function confirm_fufilment(Request $request, string $hash): JsonResponse
    {
        $validated = $request->validate(['code' => 'required|numeric']);

        $this->fulfilments->confirm($hash, $validated['code']);

        return response()->json([
            'status' => 'success',
            'message' => 'Succes Order Fulfilled',
        ]);
    }
}
