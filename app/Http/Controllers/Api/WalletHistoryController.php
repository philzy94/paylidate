<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\WalletService;

class WalletHistoryController extends Controller
{
    public function index(WalletService $wallets)
    {
        return $this->success($wallets->historyFor(auth('api')->user()));
    }
}
