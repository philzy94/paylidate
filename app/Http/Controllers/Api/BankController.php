<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\BankService;
use Illuminate\Http\Request;

class BankController extends Controller
{
    protected $banks;

    public function __construct(BankService $banks)
    {
        $this->banks = $banks;
    }

    // the caller's own bank account
    public function index()
    {
        return $this->success($this->banks->forUser(auth('api')->user()));
    }

    public function verify_account_number(Request $request)
    {
        $data = $request->validate([
            'account_number' => 'required|digits:10',
            'bank_code' => 'required|string|min:3',
        ]);

        return $this->success($this->banks->resolve($data['account_number'], $data['bank_code']));
    }

    public function store(Request $request)
    {
        return $this->success($this->banks->save(auth('api')->user(), $this->validated($request)));
    }

    // {id} is ignored: users can only ever see their own bank account
    public function show($id)
    {
        return $this->success($this->banks->find(auth('api')->user()));
    }

    public function update(Request $request, $id)
    {
        $this->banks->find(auth('api')->user());

        return $this->success($this->banks->save(auth('api')->user(), $this->validated($request)), 'Update successful');
    }

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
