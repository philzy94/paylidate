<?php

namespace Tests\Feature;

use App\Mail\ForgotPasswordMail;
use App\PasswordReset;
use App\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

class PasswordResetTest extends TestCase
{
    use RefreshDatabase;

    private function requestToken(User $user): string
    {
        $token = null;
        Mail::fake();

        $this->postJson('/api/password/create', ['email' => $user->email])->assertOk();

        Mail::assertSent(ForgotPasswordMail::class, function ($mail) use (&$token) {
            $token = basename($mail->passwordResetLink);

            return true;
        });

        return $token;
    }

    public function testRequestEmailsALinkAndStoresOnlyAHashOfTheToken()
    {
        $user = User::factory()->create();

        $token = $this->requestToken($user);

        $this->assertNotEmpty($token);
        $this->assertDatabaseMissing('password_resets', ['token' => $token]);
        $this->assertDatabaseHas('password_resets', ['email' => $user->email, 'token' => hash('sha256', $token)]);
    }

    public function testUnknownAddressesGetTheSameResponseAndNoEmail()
    {
        Mail::fake();
        $known = User::factory()->create();

        $a = $this->postJson('/api/password/create', ['email' => $known->email]);
        $b = $this->postJson('/api/password/create', ['email' => 'nobody@example.test']);

        $this->assertSame($a->json(), $b->json());
        Mail::assertSent(ForgotPasswordMail::class, 1);
    }

    public function testRequestingAgainInvalidatesTheEarlierToken()
    {
        $user = User::factory()->create();
        $first = $this->requestToken($user);
        $second = $this->requestToken($user);

        $this->getJson("/api/password/find/{$first}")->assertStatus(400);
        $this->getJson("/api/password/find/{$second}")->assertOk()->assertJsonPath('email', $user->email);
    }

    public function testResetChangesThePasswordAndConsumesTheToken()
    {
        $user = User::factory()->create();
        $token = $this->requestToken($user);

        $this->postJson('/api/password/reset', [
            'email' => $user->email,
            'token' => $token,
            'password' => 'brand-new-pass',
            'password_confirmation' => 'brand-new-pass',
        ])->assertOk();

        $this->assertTrue(Hash::check('brand-new-pass', $user->fresh()->password));
        $this->assertSame(0, PasswordReset::count());
        $this->getJson("/api/password/find/{$token}")->assertStatus(400);
    }

    public function testResetRejectsAMismatchedEmail()
    {
        $user = User::factory()->create();
        $other = User::factory()->create();
        $token = $this->requestToken($user);

        $this->postJson('/api/password/reset', [
            'email' => $other->email,
            'token' => $token,
            'password' => 'brand-new-pass',
            'password_confirmation' => 'brand-new-pass',
        ])->assertStatus(400)->assertJsonPath('code', 'token_invalid');

        $this->assertFalse(Hash::check('brand-new-pass', $other->fresh()->password));
    }

    public function testResetEnforcesPasswordRules()
    {
        $user = User::factory()->create();
        $token = $this->requestToken($user);

        $this->postJson('/api/password/reset', ['email' => $user->email, 'token' => $token, 'password' => 'short', 'password_confirmation' => 'short'])
            ->assertStatus(422)
            ->assertJsonValidationErrors('password');
    }

    public function testExpiredTokensAreRejectedAndRemoved()
    {
        $user = User::factory()->create();
        $token = $this->requestToken($user);
        PasswordReset::where('email', $user->email)->update(['created_at' => now()->subMinutes(61)]);

        $this->getJson("/api/password/find/{$token}")->assertStatus(400)->assertJsonPath('code', 'token_invalid');

        $this->assertSame(0, PasswordReset::count());
    }

    public function testRequestsAreRateLimited()
    {
        Mail::fake();

        for ($i = 0; $i < 5; $i++) {
            $this->postJson('/api/password/create', ['email' => 'a@example.test'])->assertOk();
        }

        $this->postJson('/api/password/create', ['email' => 'a@example.test'])->assertStatus(429);
    }
}
