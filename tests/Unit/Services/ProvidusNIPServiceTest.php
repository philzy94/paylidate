<?php

namespace Tests\Unit\Services;

use App\Services\ProvidusNIPService;
use Tests\TestCase;

class ProvidusNIPServiceTest extends TestCase
{
    private function service(array $overrides = []): ProvidusNIPService
    {
        config(['services.providus' => array_merge([
            'base_url' => 'https://providus.test/api/',
            'username' => 'configured-user',
            'password' => 'configured-secret',
            'auth_signature' => 'sig-123',
            'client_id' => 'client-456',
        ], $overrides)]);

        return new ProvidusNIPService();
    }

    private function property(ProvidusNIPService $service, string $name)
    {
        $property = new \ReflectionProperty($service, $name);
        $property->setAccessible(true);

        return $property->getValue($service);
    }

    public function testCredentialsAreReadFromConfig()
    {
        $service = $this->service();

        $this->assertSame('configured-user', $this->property($service, 'username'));
        $this->assertSame('configured-secret', $this->property($service, 'password'));
        $this->assertSame('https://providus.test/api/', $service->baseURL);
    }

    public function testRequestHeadersAreBuiltFromConfig()
    {
        $headers = $this->property($this->service(), 'header');

        $this->assertContains('X-Auth-Signature: sig-123', $headers);
        $this->assertContains('Client-Id: client-456', $headers);
    }

    public function testNoCredentialsAreHardcodedInTheClass()
    {
        $source = file_get_contents(app_path('Services/ProvidusNIPService.php'));

        $this->assertStringNotContainsString("password = 'password'", $source);
        $this->assertStringNotContainsString('X-Auth-Signature: BE09', $source);
        $this->assertStringNotContainsString('154.113', $source);
    }

    public function testMissingPasswordIsNotDefaulted()
    {
        $service = $this->service(['password' => null]);

        $this->assertNull($this->property($service, 'password'));
    }
}
