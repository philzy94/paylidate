<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Only lets administrators through; everyone else gets a JSON 403.
 */
class EnsureAdmin
{
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user('api');

        if (! $user || ! $user->is_admin) {
            return response()->json([
                'status' => 'error',
                'code' => 'forbidden',
                'message' => 'You are not allowed to perform this action',
            ], 403);
        }

        return $next($request);
    }
}
