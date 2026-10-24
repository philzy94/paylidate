<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Passport\ClientRepository;
use Tests\TestCase;

class InstallCommandTest extends TestCase
{
    use RefreshDatabase;

    public function testItCreatesAPersonalAccessClientOnce()
    {
        $this->artisan('paylidate:install')->assertExitCode(0);
        $this->artisan('paylidate:install')->assertExitCode(0);

        $client = app(ClientRepository::class)->personalAccessClient('users');

        $this->assertTrue($client->hasGrantType('personal_access'));
        $this->assertSame(1, \Laravel\Passport\Passport::client()->newQuery()->count());
    }

    public function testSignupWorksAfterInstall()
    {
        $this->artisan('paylidate:install')->assertExitCode(0);

        $this->postJson('/api/signup', [
            'name' => 'Ada Lovelace',
            'email' => 'ada@example.test',
            'phone' => '08012345678',
            'password' => 'secret-pass',
            'password_confirmation' => 'secret-pass',
        ])->assertOk()->assertJsonStructure(['access_token']);
    }
}
