<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\ReportingService;
use Illuminate\Http\JsonResponse;

class DashboardController extends Controller
{
    public function index(ReportingService $reports): JsonResponse
    {
        return $this->success($reports->dashboard($this->authUser()));
    }
}
