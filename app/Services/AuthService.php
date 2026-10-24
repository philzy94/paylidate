<?php

namespace App\Services;

use App\Exceptions\AuthException;
use App\Mail\RegistrationMail;
use App\Rating;
use App\User;
use Carbon\Carbon;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;

/**
 * Signup and login rules shared by the first-party and third-party APIs.
 */
class AuthService
{
    /**
     * Register a user, or let someone claim a deactivated/placeholder account.
     *
     * @param  array<string, mixed>  $data  name, email, password and optional phone
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

        if (! $user instanceof User) {
            throw AuthException::invalidCredentials();
        }

        if (! $user->active) {
            throw AuthException::inactive();
        }

        return ['user' => $user, 'token' => $this->issueToken($user, $rememberMe)];
    }

    public function issueToken(User $user, bool $rememberMe = false): string
    {
        $result = $user->createToken('Personal Access Token');

        $token = $result->getToken();

        if ($token !== null) {
            if ($rememberMe) {
                $token->expires_at = Carbon::now()->addWeeks(4);
            }

            $token->save();
        }

        return $result->accessToken;
    }

    /** @param array<string, mixed> $data */
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

    /** @param array<string, mixed> $data */
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

    /**
     * Update the caller's own profile. Only whitelisted fields can change, so a client
     * can never promote itself to admin or reactivate an account.
     */
    /** @param array<string, mixed> $data */
    public function updateProfile(User $user, array $data): User
    {
        $user->update(array_intersect_key($data, array_flip(['name', 'phone', 'avatar'])));

        return $user->refresh();
    }

    /**
     * Confirm the caller's email address with the token that was mailed to them.
     *
     * @throws AuthException
     */
    public function verifyEmail(User $user, string $token): User
    {
        if (! $user->email_token || ! hash_equals($user->email_token, $token)) {
            throw AuthException::tokenInvalid();
        }

        $user->forceFill(['email_verified_at' => now(), 'email_token' => null])->save();

        return $user;
    }

    /**
     * Activate an account from the link in the signup email.
     *
     * @throws AuthException
     */
    public function activate(string $token): User
    {
        $user = User::where('email_token', $token)->first();

        if (! $user) {
            throw AuthException::tokenInvalid();
        }

        $user->forceFill(['active' => true, 'email_verified_at' => now(), 'email_token' => null])->save();

        return $user;
    }

    /**
     * Email a fresh verification link to the caller.
     */
    public function resendVerification(User $user): void
    {
        $token = Str::random(8) . date('dmyHis');

        $user->forceFill(['email_verified_at' => null, 'email_token' => $token])->save();

        Mail::to($user->email)->send(new RegistrationMail($user, config('app.frontend_url') . '/verify/' . $token));
    }

    /**
     * Rate another user from 1 to 5; rating again replaces the earlier score.
     *
     * @throws AuthException
     */
    public function rate(User $rater, int $ratedUserId, int $score): Rating
    {
        if ($rater->id === $ratedUserId) {
            throw AuthException::cannotRateSelf();
        }

        if (! User::whereKey($ratedUserId)->exists()) {
            throw AuthException::userNotFound();
        }

        return Rating::updateOrCreate(
            ['user_id' => $rater->id, 'rated_user_id' => $ratedUserId],
            ['rating' => $score]
        );
    }

    /**
     * @return array{average: float, count: int}
     */
    public function ratingsFor(int $userId): array
    {
        $ratings = Rating::where('rated_user_id', $userId);

        return [
            'average' => round((float) $ratings->avg('rating'), 2),
            'count' => $ratings->count(),
        ];
    }

    public function emailExists(string $email): bool
    {
        return User::where('email', strtolower($email))->exists();
    }
}
