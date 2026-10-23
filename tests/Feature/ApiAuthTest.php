<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ApiAuthTest extends TestCase
{
    use RefreshDatabase;

    public function testHomePageLoads()
    {
        $this->get('/')->assertStatus(200);
    }

    public function testLoginRequiresCredentials()
    {
        $this->postJson('/api/login', [])->assertStatus(422);
    }

    public function testProtectedPaymentsRouteRejectsGuests()
    {
        $this->getJson('/api/payment')->assertStatus(401);
    }
}
