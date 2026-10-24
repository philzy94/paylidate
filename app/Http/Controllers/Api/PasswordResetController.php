<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\PasswordResetService;
use Illuminate\Http\Request;

class PasswordResetController extends Controller
{
    protected $resets;

    public function __construct(PasswordResetService $resets)
    {
        $this->resets = $resets;
    }

    public function create(Request $request)
    {
        $data = $request->validate(['email' => 'required|string|email']);

        $this->resets->request($data['email']);

        return response()->json([
            'status' => 'success',
            'message' => 'If that e-mail address is registered, we have e-mailed a password reset link.',
        ]);
    }

    public function find($token)
    {
        $reset = $this->resets->find($token);

        return response()->json(['email' => $reset->email, 'created_at' => $reset->created_at]);
    }

    public function reset(Request $request)
    {
        $data = $request->validate([
            'email' => 'required|string|email',
            'password' => 'required|string|min:8|confirmed',
            'token' => 'required|string',
        ]);

        $this->resets->reset($data['email'], $data['token'], $data['password']);

        return response()->json([
            'status' => 'success',
            'message' => 'Password reset successful. Please login',
        ]);
    }
}
