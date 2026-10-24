<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\BankService;
use App\Services\ReportingService;
use Illuminate\Http\JsonResponse;

class RefererController extends Controller
{
    protected ReportingService $reports;

    public function __construct(ReportingService $reports)
    {
        $this->reports = $reports;
    }

    // referral bonuses and the bank account they will be paid to
    public function index(BankService $banks): JsonResponse
    {
        $user = $this->authUser();

        return $this->success([$this->reports->bonuses($user), $banks->forUser($user)]);
    }

    public function get_referer(): JsonResponse
    {
        return $this->success($this->reports->referralSummary($this->authUser()));
    }
}
