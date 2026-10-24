<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\WalletHistory;

class WalletHistoryController extends Controller
{
    public function index()
    {
        return $this->success(WalletHistory::where('user_id', auth('api')->user()->id)->orderByDesc('created_at')->get());
    }
}
