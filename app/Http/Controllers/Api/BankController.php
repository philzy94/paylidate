<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\BankService;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;

class BankController extends Controller
{
    protected BankService $banks;

    public function __construct(BankService $banks)
    {
        $this->banks = $banks;
    }

    // the caller's own bank account
    public function index(): JsonResponse
    {
        return $this->success($this->banks->forUser($this->authUser()));
    }

    public function verify_account_number(Request $request): JsonResponse
    {
        $data = $request->validate([
            'account_number' => 'required|digits:10',
            'bank_code' => 'required|string|min:3',
        ]);

        return $this->success($this->banks->resolve($data['account_number'], $data['bank_code']));
    }

    public function store(Request $request): JsonResponse
    {
        return $this->success($this->banks->save($this->authUser(), $this->validated($request)));
    }

    // {id} is ignored: users can only ever see their own bank account
    public function show(string $id): JsonResponse
    {
        return $this->success($this->banks->find($this->authUser()));
    }

    public function update(Request $request, string $id): JsonResponse
    {
        $this->banks->find($this->authUser());

        return $this->success($this->banks->save($this->authUser(), $this->validated($request)), 'Update successful');
    }

    /**
     * @return array<string, mixed>
     */
    protected function validated(Request $request): array
    {
        return $request->validate([
            'account_name' => 'required|string',
            'bank_name' => 'required|string',
            'account_number' => 'required|digits:10',
            'bank_code' => 'required|string|min:3',
            'branch_name' => 'nullable|string',
        ]);
    }
}
