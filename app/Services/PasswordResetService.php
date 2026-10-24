<?php

namespace App\Services;

use App\Exceptions\AuthException;
use App\Mail\ForgotPasswordMail;
use App\PasswordReset;
use App\User;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;

class PasswordResetService
{
    /** Minutes a reset link stays valid. */
    public const TTL_MINUTES = 60;

    /**
     * Email a reset link when the address belongs to an active account.
     * Callers respond identically either way so addresses cannot be enumerated.
     */
    public function request(string $email): void
    {
        $email = strtolower($email);
        $user = User::where('email', $email)->first();

        PasswordReset::where('email', $email)->delete();

        if (! $user || $user->canBeClaimed()) {
            return;
        }

        $token = Str::random(60) . $user->id;

        PasswordReset::create([
            'email' => $email,
            'token' => $this->hash($token),
            'created_at' => Carbon::now(),
        ]);

        Mail::to($email)->send(new ForgotPasswordMail($user, config('app.frontend_url') . '/reset-password/' . $token));
    }

    /**
     * @throws AuthException when the token is unknown or older than the TTL
     */
    public function find(string $token): PasswordReset
    {
        $reset = PasswordReset::where('token', $this->hash($token))->first();

        if (! $reset) {
            throw AuthException::tokenInvalid();
        }

        if (Carbon::parse($reset->created_at)->addMinutes(self::TTL_MINUTES)->isPast()) {
            PasswordReset::where('email', $reset->email)->delete();

            throw AuthException::tokenInvalid();
        }

        return $reset;
    }

    /**
     * Set a new password and sign the user out everywhere.
     *
     * @throws AuthException
     */
    public function reset(string $email, string $token, string $password): void
    {
        $reset = $this->find($token);

        if ($reset->email !== strtolower($email)) {
            throw AuthException::tokenInvalid();
        }

        $user = User::where('email', $reset->email)->first();

        if (! $user) {
            throw AuthException::tokenInvalid();
        }

        DB::transaction(function () use ($user, $password, $reset) {
            $user->forceFill(['password' => Hash::make($password), 'needs_password_reset' => false])->save();
            $user->tokens()->update(['revoked' => true]);
            PasswordReset::where('email', $reset->email)->delete();
        });
    }

    protected function hash(string $token): string
    {
        return hash('sha256', $token);
    }
}
