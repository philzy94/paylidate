<?php

namespace Tests;

use Illuminate\Foundation\Testing\TestCase as BaseTestCase;

abstract class TestCase extends BaseTestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        // Passport's guard needs an RSA key pair; keys are git-ignored.
        if (! file_exists(storage_path('oauth-private.key'))) {
            $this->artisan('passport:keys');
        }
    }
}
