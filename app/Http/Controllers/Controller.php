<?php

namespace App\Http\Controllers;

use App\Exceptions\AuthException;
use App\User;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Illuminate\Foundation\Bus\DispatchesJobs;
use Illuminate\Foundation\Validation\ValidatesRequests;
use Illuminate\Http\JsonResponse;
use Illuminate\Routing\Controller as BaseController;

class Controller extends BaseController
{
    use AuthorizesRequests;
    use DispatchesJobs;
    use ValidatesRequests;

    /**
     * The authenticated API user. Routes using this sit behind `auth:api`, so
     * a missing user means a misconfigured route and is refused.
     */
    protected function authUser(): User
    {
        $user = auth('api')->user();

        if (! $user instanceof User) {
            throw AuthException::forbidden();
        }

        return $user;
    }

    /**
     * The API user when a token was sent, null for guests on public routes.
     */
    protected function optionalUser(): ?User
    {
        $user = auth('api')->user();

        return $user instanceof User ? $user : null;
    }

    protected function success(mixed $data, string $message = 'success'): JsonResponse
    {
        return response()->json([
            'status' => 'success',
            'message' => $message,
            'data' => $data,
        ]);
    }
}
