<?php

namespace Tests\Feature;

use Tests\TestCase;

class ApiAuthTest extends TestCase
{
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
