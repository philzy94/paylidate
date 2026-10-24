<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\ReportingService;

class DashboardController extends Controller
{
    public function index(ReportingService $reports)
    {
        return $this->success($reports->dashboard(auth('api')->user()));
    }
}
