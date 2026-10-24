<?php

namespace App\Http\Controllers\Api;

use App\Exceptions\AuthException;
use App\Http\Controllers\Controller;
use App\Services\AuthService;
use Illuminate\Http\Request;
use Laravel\Passport\Token;
use Illuminate\Http\JsonResponse;

/**
 * @group  Authentication management
 *
 * APIs for Authenticating users
 */
class AuthController extends Controller
{
    public function signup(Request $request, AuthService $auth): JsonResponse
    {
        $data = $request->validate([
            'name' => 'required|string|min:3',
            'email' => 'required|string|email|max:255',
            'password' => 'required|string|min:8',
            'password_confirmation' => 'required|same:password',
            'phone' => 'required|unique:users',
        ]);

        $result = $auth->register($data);

        return $this->registered($result);
    }


    public function refferal_signup(Request $request, AuthService $auth, string $ref): JsonResponse
    {
        $data = $request->validate([
            'name' => 'required|string|min:3',
            'email' => 'required|string|email|max:255',
            'password' => 'required|string|min:8',
            'password_confirmation' => 'required|same:password',
            'phone' => 'required|unique:users',
        ]);

        return $this->registered($auth->register($data, $ref));
    }

    /**
     * @param array{token: string, user: \App\User} $result
     */
    protected function registered(array $result): JsonResponse
    {
        return response()->json([
            'status' => 'success',
            'message' => 'User created',
            'access_token' => $result['token'],
            'data' => $result['user'],
        ]);
    }

    /**
     * Login user and create token
     *
     * @bodyParam email string required the email of the user
     * @bodyParam password string required the users prefered password
     * @bodyParam  remember_me boolean
     */
    public function login(Request $request, AuthService $auth): JsonResponse
    {
        $request->validate([
            'email' => 'required|string|email',
            'password' => 'required|string',
        ]);

        $result = $auth->login($request->email, $request->password, (bool) $request->remember_me);

        return response()->json([
            'status' => 'success',
            'access_token' => $result['token'],
            'message' => 'login successful',
            'data' => $result['user'],
        ]);
    }

    /**
     * Logout user (Revoke the token)
     *
     * @authenticated
     */
    public function logout(Request $request): JsonResponse
    {
        $token = $this->authUser()->token();

        if ($token instanceof Token) {
            $token->revoke();
        }


        return response()->json([
            'status' => 'success',
            'message' => 'Logged out'
        ]);
    }

    /**
     * Get the authenticated User
     *
     * @authenticated
     */
    public function user(Request $request): JsonResponse
    {
        $user = $this->authUser()->load('account');

        return response()->json([
            'status' => 'success',
            'message' => 'user fetched',
            'data' => $user,
            'account' => $user->account,
        ]);
    }

    public function verifyEmail(Request $request, AuthService $auth, string $token): JsonResponse
    {
        $user = $auth->verifyEmail($this->authUser(), $token);

        return response()->json([
            'status' => 'success',
            'message' => 'Email verified',
            'data' => $user,
        ]);
    }

    public function resendVerificationEmail(Request $request, AuthService $auth, string $email): JsonResponse
    {
        if (strtolower($email) !== $this->authUser()->email) {
            throw AuthException::forbidden();
        }

        $auth->resendVerification($this->authUser());

        return response()->json([
            'status' => 'success',
            'message' => 'Email verification link sent',
        ]);
    }

    /**
     * User Activation
     *
     * @urlParam  token string required the token sent to the users email address
     */
    public function signupActivate(AuthService $auth, string $token): JsonResponse
    {
        return response()->json([
            'status' => 'success',
            'message' => 'user activated',
            'data' => $auth->activate($token),
        ]);
    }

    /**
     * Update the authenticated user's profile
     *
     * @bodyParam name string the full name of the user
     * @bodyParam phone string the valid phone number of the user, this value is unique
     */
    public function update(Request $request, AuthService $auth): JsonResponse
    {
        $data = $request->validate([
            'name' => 'sometimes|required|string|min:3',
            'phone' => 'sometimes|required|string|unique:users,phone,' . $this->authUser()->id,
            'avatar' => 'sometimes|nullable|string',
        ]);

        return response()->json([
            'status' => 'success',
            'message' => 'User updated',
            'data' => $auth->updateProfile($this->authUser(), $data),
        ]);
    }

    public function check_email(AuthService $auth, string $email): JsonResponse
    {
        $exists = $auth->emailExists($email);

        return response()->json([
            'status' => $exists ? 'true' : 'false',
            'message' => $exists ? 'User exist.' : 'User doesnot exist.',
        ], $exists ? 200 : 406);
    }

    public function rate_user(Request $request, AuthService $auth, string $id): JsonResponse
    {
        $data = $request->validate(['rating' => 'required|integer|between:1,5']);

        $auth->rate($this->authUser(), (int) $id, $data['rating']);

        return response()->json([
            'status' => 'success',
            'message' => 'User rated successfully',
        ]);
    }

    public function get_user_ratings(AuthService $auth, string $id): JsonResponse
    {
        return response()->json([
            'status' => 'success',
            'message' => 'ratings found',
            'data' => $auth->ratingsFor((int) $id),
        ]);
    }
}
