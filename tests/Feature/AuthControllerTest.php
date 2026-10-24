<?php

namespace Tests\Feature;

use App\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class AuthControllerTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->artisan('passport:client', [
            '--personal' => true,
            '--name' => 'Test Personal Access Client',
            '--provider' => 'users',
        ]);
    }

    private function payload(array $overrides = []): array
    {
        return array_merge([
            'name' => 'Ada Lovelace',
            'email' => 'ada@example.test',
            'phone' => '08012345678',
            'password' => 'secret-pass',
            'password_confirmation' => 'secret-pass',
        ], $overrides);
    }

    public function testSignupCreatesUserAndReturnsToken()
    {
        $this->postJson('/api/signup', $this->payload())
            ->assertOk()
            ->assertJsonPath('status', 'success')
            ->assertJsonStructure(['access_token', 'data' => ['id', 'email']]);

        $user = User::where('email', 'ada@example.test')->first();
        $this->assertTrue(Hash::check('secret-pass', $user->password));
        $this->assertNotEmpty($user->referral_token);
    }

    public function testSignupValidatesInput()
    {
        $this->postJson('/api/signup', ['email' => 'not-an-email', 'password' => 'a'])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['name', 'email', 'password_confirmation', 'phone']);
    }

    public function testSignupRejectsAnActiveExistingUser()
    {
        User::factory()->create(['email' => 'ada@example.test', 'phone' => '0800']);

        $this->postJson('/api/signup', $this->payload())
            ->assertStatus(409)
            ->assertJsonPath('status', 'exist')
            ->assertJsonPath('code', 'user_exists');
    }

    public function testSignupLetsSomeoneClaimAnAccountFlaggedForPasswordReset()
    {
        $placeholder = User::factory()->create([
            'email' => 'ada@example.test',
            'active' => true,
            'needs_password_reset' => true,
        ]);

        $this->postJson('/api/signup', $this->payload())->assertOk();

        $placeholder->refresh();
        $this->assertSame('Ada Lovelace', $placeholder->name);
        $this->assertFalse((bool) $placeholder->needs_password_reset);
        $this->assertTrue(Hash::check('secret-pass', $placeholder->password));
        $this->assertSame(1, User::count());
    }

    public function testSignupLetsSomeoneClaimADeactivatedAccount()
    {
        User::factory()->create(['email' => 'ada@example.test', 'active' => false]);

        $this->postJson('/api/signup', $this->payload())->assertOk();

        $this->assertTrue((bool) User::first()->active);
    }

    public function testReferralSignupLinksTheReferrer()
    {
        $referrer = User::factory()->create();

        $this->postJson('/api/signup/referral/' . $referrer->referral_token, $this->payload())->assertOk();

        $this->assertSame((string) $referrer->id, User::where('email', 'ada@example.test')->first()->referred_by);
    }

    public function testReferralSignupRejectsAnUnknownToken()
    {
        $this->postJson('/api/signup/referral/unknown', $this->payload())
            ->assertStatus(422)
            ->assertJsonPath('code', 'invalid_referral');

        $this->assertSame(0, User::count());
    }

    public function testLoginReturnsTokenForValidCredentials()
    {
        User::factory()->create(['email' => 'ada@example.test', 'password' => Hash::make('secret-pass')]);

        $this->postJson('/api/login', ['email' => 'ADA@example.test', 'password' => 'secret-pass'])
            ->assertOk()
            ->assertJsonPath('message', 'login successful')
            ->assertJsonStructure(['access_token']);
    }

    public function testLoginRejectsWrongPassword()
    {
        User::factory()->create(['email' => 'ada@example.test', 'password' => Hash::make('secret-pass')]);

        $this->postJson('/api/login', ['email' => 'ada@example.test', 'password' => 'wrong'])
            ->assertStatus(401)
            ->assertJsonPath('status', 'failed')
            ->assertJsonPath('code', 'invalid_credentials');
    }

    public function testLoginRejectsInactiveAccounts()
    {
        User::factory()->create([
            'email' => 'ada@example.test',
            'password' => Hash::make('secret-pass'),
            'active' => false,
        ]);

        $this->postJson('/api/login', ['email' => 'ada@example.test', 'password' => 'secret-pass'])
            ->assertStatus(401)
            ->assertJsonPath('code', 'account_inactive');
    }

    public function testRegisterIsUsableFromAnyController()
    {
        $result = app(\App\Services\AuthService::class)->register($this->payload());

        $this->assertSame('ada@example.test', $result['user']->email);
        $this->assertNotEmpty($result['token']);
    }
}
