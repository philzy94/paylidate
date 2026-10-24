<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\WalletService;
use Illuminate\Http\JsonResponse;

class WalletHistoryController extends Controller
{
    public function index(WalletService $wallets): JsonResponse
    {
        return $this->success($wallets->historyFor($this->authUser()));
    }
}
