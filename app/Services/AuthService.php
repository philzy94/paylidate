<?php

namespace App\Services;

use App\Exceptions\AuthException;
use App\User;
use Carbon\Carbon;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

/**
 * Signup and login rules shared by the first-party and third-party APIs.
 */
class AuthService
{
    /**
     * Register a user, or let someone claim a deactivated/placeholder account.
     *
     * @param  array  $data  name, email, password and optional phone
     * @return array{user: User, token: string}
     *
     * @throws AuthException
     */
    public function register(array $data, ?string $referralToken = null): array
    {
        $referrer = null;

        if ($referralToken !== null) {
            $referrer = User::where('referral_token', $referralToken)->first();

            if (! $referrer) {
                throw AuthException::invalidReferral();
            }
        }

        $existing = User::where('email', strtolower($data['email']))->first();

        if ($existing && ! $existing->canBeClaimed()) {
            throw AuthException::userExists();
        }

        $user = DB::transaction(function () use ($data, $existing, $referrer) {
            return $existing ? $this->claim($existing, $data) : $this->create($data, $referrer);
        });

        return ['user' => $user, 'token' => $this->issueToken($user)];
    }

    /**
     * Check credentials and issue an access token.
     *
     * @return array{user: User, token: string}
     *
     * @throws AuthException
     */
    public function login(string $email, string $password, bool $rememberMe = false): array
    {
        $attempt = Auth::attempt([
            'email' => strtolower($email),
            'password' => $password,
            'deleted_at' => null,
        ]);

        if (! $attempt) {
            throw AuthException::invalidCredentials();
        }

        $user = Auth::user();

        if (! $user->active) {
            throw AuthException::inactive();
        }

        return ['user' => $user, 'token' => $this->issueToken($user, $rememberMe)];
    }

    public function issueToken(User $user, bool $rememberMe = false): string
    {
        $result = $user->createToken('Personal Access Token');

        if ($rememberMe) {
            $result->token->expires_at = Carbon::now()->addWeeks(4);
        }

        $result->token->save();

        return $result->accessToken;
    }

    protected function claim(User $user, array $data): User
    {
        $user->update([
            'name' => $data['name'],
            'phone' => $data['phone'] ?? null,
            'password' => Hash::make($data['password']),
            'active' => true,
            'needs_password_reset' => false,
            'referral_token' => $this->referralToken(),
        ]);

        return $user;
    }

    protected function create(array $data, ?User $referrer): User
    {
        return User::create([
            'name' => $data['name'],
            'email' => $data['email'],
            'phone' => $data['phone'] ?? null,
            'password' => Hash::make($data['password']),
            'email_token' => Str::random(8) . date('dmyHis'),
            'username' => 'user_' . rand(1, 400) . date('dmyHis'),
            'referral_token' => $this->referralToken(),
            'referred_by' => $referrer ? $referrer->id : null,
        ]);
    }

    protected function referralToken(): string
    {
        return Str::random(10) . date('dmyHis');
    }
}
