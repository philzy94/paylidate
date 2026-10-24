<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\FulfilmentService;
use Illuminate\Http\Request;

class FulfilmentController extends Controller
{
    protected $fulfilments;

    public function __construct(FulfilmentService $fulfilments)
    {
        $this->fulfilments = $fulfilments;
    }

    public function get_transaction($hash)
    {
        return $this->success($this->fulfilments->details($hash));
    }

    public function confirm_fufilment(Request $request, $hash)
    {
        $validated = $request->validate(['code' => 'required|numeric']);

        $this->fulfilments->confirm($hash, $validated['code']);

        return response()->json([
            'status' => 'success',
            'message' => 'Succes Order Fulfilled',
        ]);
    }
}
