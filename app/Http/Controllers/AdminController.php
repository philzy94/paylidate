<?php

namespace App\Http\Controllers;

use App\Services\AdminService;
use Illuminate\Http\JsonResponse;

/**
 * Admin reporting. Every route is behind the `admin` middleware.
 */
class AdminController extends Controller
{
    protected AdminService $admin;

    public function __construct(AdminService $admin)
    {
        $this->admin = $admin;
    }

    public function users(): JsonResponse
    {
        return $this->success($this->admin->users());
    }

    public function userBtwnDate(string $startDate, string $endDate): JsonResponse
    {
        return $this->success($this->admin->usersBetween($startDate, $endDate));
    }

    public function numbers_of(): JsonResponse
    {
        return $this->success($this->admin->overview());
    }
}
