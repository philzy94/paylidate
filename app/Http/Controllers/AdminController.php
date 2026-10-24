<?php

namespace App\Http\Controllers;

use App\Services\AdminService;

/**
 * Admin reporting. Every route is behind the `admin` middleware.
 */
class AdminController extends Controller
{
    protected $admin;

    public function __construct(AdminService $admin)
    {
        $this->admin = $admin;
    }

    public function users()
    {
        return $this->success($this->admin->users());
    }

    public function userBtwnDate($startDate, $endDate)
    {
        return $this->success($this->admin->usersBetween($startDate, $endDate));
    }

    public function numbers_of()
    {
        return $this->success($this->admin->overview());
    }
}
