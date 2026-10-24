<?php

namespace Tests\Feature;

use App\Mail\RegistrationMail;
use App\Rating;
use App\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Laravel\Passport\Passport;
use Tests\TestCase;

class AccountManagementTest extends TestCase
{
    use RefreshDatabase;

    private function signIn(User $user): User
    {
        Passport::actingAs($user, [], 'api');

        return $user;
    }

    public function testProfileUpdateIgnoresPrivilegedFields()
    {
        $user = $this->signIn(User::factory()->create(['is_admin' => false, 'active' => true, 'email' => 'me@example.test']));

        $this->postJson('/api/user/update', [
            'name' => 'New Name',
            'phone' => '08055555555',
            'is_admin' => true,
            'is_staff' => true,
            'active' => false,
            'email' => 'attacker@example.test',
            'password' => 'overwritten',
        ])->assertOk()->assertJsonPath('data.name', 'New Name');

        $user->refresh();
        $this->assertSame('08055555555', $user->phone);
        $this->assertFalse((bool) $user->is_admin);
        $this->assertFalse((bool) $user->is_staff);
        $this->assertTrue((bool) $user->active);
        $this->assertSame('me@example.test', $user->email);
        $this->assertNotSame('overwritten', $user->password);
    }

    public function testProfileUpdateRejectsAnotherUsersPhoneNumber()
    {
        User::factory()->create(['phone' => '08066666666']);
        $this->signIn(User::factory()->create());

        $this->postJson('/api/user/update', ['phone' => '08066666666'])->assertStatus(422)->assertJsonValidationErrors('phone');
    }

    public function testCurrentUserEndpoint()
    {
        $user = $this->signIn(User::factory()->create());

        $this->getJson('/api/user')->assertOk()->assertJsonPath('data.id', $user->id);
    }

    public function testVerifyEmailMarksTheCallerVerified()
    {
        $user = $this->signIn(User::factory()->create(['email_verified_at' => null]));
        $user->forceFill(['email_token' => 'TOKEN123'])->save();

        $this->postJson('/api/verifyEmail/TOKEN123')->assertOk();

        $user->refresh();
        $this->assertNotNull($user->email_verified_at);
        $this->assertNull($user->email_token);
    }

    public function testVerifyEmailRejectsSomeoneElsesToken()
    {
        $other = User::factory()->create();
        $other->forceFill(['email_token' => 'OTHERS'])->save();
        $this->signIn(User::factory()->create());

        $this->postJson('/api/verifyEmail/OTHERS')->assertStatus(400)->assertJsonPath('code', 'token_invalid');

        $this->assertSame('OTHERS', $other->fresh()->email_token);
    }

    public function testResendVerificationIsLimitedToTheCallersOwnAddress()
    {
        Mail::fake();
        $me = $this->signIn(User::factory()->create(['email' => 'me@example.test']));
        $victim = User::factory()->create(['email' => 'victim@example.test']);

        $this->postJson('/api/resendVerificationEmail/victim@example.test')->assertStatus(403);
        $this->assertNotNull($victim->fresh()->email_verified_at);

        $this->postJson('/api/resendVerificationEmail/me@example.test')->assertOk();
        Mail::assertQueued(RegistrationMail::class, fn ($mail) => $mail->hasTo('me@example.test'));
        $this->assertNotNull($me->fresh()->email_token);
    }

    public function testActivationLinkActivatesTheAccount()
    {
        $user = User::factory()->create(['active' => false]);
        $user->forceFill(['email_token' => 'ACT123'])->save();

        $this->getJson('/api/signup/activate/ACT123')->assertOk();

        $this->assertTrue((bool) $user->fresh()->active);
        $this->getJson('/api/signup/activate/ACT123')->assertStatus(400);
    }

    public function testCheckEmail()
    {
        User::factory()->create(['email' => 'here@example.test']);

        $this->getJson('/api/check/email/here@example.test')->assertOk()->assertJsonPath('status', 'true');
        $this->getJson('/api/check/email/gone@example.test')->assertStatus(406);
    }

    public function testRatingsAreAveragedAndCannotBeSelfAwarded()
    {
        $subject = User::factory()->create();
        $a = User::factory()->create();
        $b = User::factory()->create();

        $this->signIn($a);
        $this->postJson("/api/rate-user/{$subject->id}", ['rating' => 5])->assertOk();
        $this->signIn($b);
        $this->postJson("/api/rate-user/{$subject->id}", ['rating' => 2])->assertOk();
        $this->postJson("/api/rate-user/{$b->id}", ['rating' => 5])->assertStatus(422)->assertJsonPath('code', 'rate_self');

        $this->getJson("/api/get-user-rate/{$subject->id}")
            ->assertOk()
            ->assertJsonPath('data.average', 3.5)
            ->assertJsonPath('data.count', 2);
    }

    public function testRatingAgainReplacesTheEarlierScore()
    {
        $subject = User::factory()->create();
        $this->signIn(User::factory()->create());

        $this->postJson("/api/rate-user/{$subject->id}", ['rating' => 1])->assertOk();
        $this->postJson("/api/rate-user/{$subject->id}", ['rating' => 4])->assertOk();

        $this->assertSame(1, Rating::count());
        $this->assertSame(4, Rating::first()->rating);
    }

    public function testRatingMustBeBetweenOneAndFive()
    {
        $subject = User::factory()->create();
        $this->signIn(User::factory()->create());

        $this->postJson("/api/rate-user/{$subject->id}", ['rating' => 9])->assertStatus(422);
        $this->postJson('/api/rate-user/99999', ['rating' => 3])->assertStatus(404);
    }

    public function testLogoutRevokesTheToken()
    {
        $this->signIn(User::factory()->create());

        $this->getJson('/api/logout')->assertOk()->assertJsonPath('message', 'Logged out');
    }
}
