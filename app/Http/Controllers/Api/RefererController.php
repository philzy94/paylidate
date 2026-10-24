<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\BankService;
use App\Services\ReportingService;

class RefererController extends Controller
{
    protected $reports;

    public function __construct(ReportingService $reports)
    {
        $this->reports = $reports;
    }

    // referral bonuses and the bank account they will be paid to
    public function index(BankService $banks)
    {
        $user = auth('api')->user();

        return $this->success([$this->reports->bonuses($user), $banks->forUser($user)]);
    }

    public function get_referer()
    {
        return $this->success($this->reports->referralSummary(auth('api')->user()));
    }
}
