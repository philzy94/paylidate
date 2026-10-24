<?php

namespace Tests\Feature;

use App\Console\Commands\OpenApiCommand;
use Tests\TestCase;

class OpenApiSpecTest extends TestCase
{
    /** @return list<string> */
    private function routeKeys(): array
    {
        return array_map(
            fn ($route) => strtoupper($route->methods()[0]) . ' ' . $route->uri(),
            OpenApiCommand::apiRoutes()
        );
    }

    public function testEveryApiRouteIsDescribed()
    {
        $missing = array_diff($this->routeKeys(), array_keys(OpenApiCommand::summaries()));

        $this->assertSame([], array_values($missing), 'Add these routes to resources/openapi/summaries.php');
    }

    public function testNoDescriptionPointsAtARemovedRoute()
    {
        $stale = array_diff(array_keys(OpenApiCommand::summaries()), $this->routeKeys());

        $this->assertSame([], array_values($stale), 'Remove these entries from resources/openapi/summaries.php');
    }

    public function testThePublishedSpecMatchesTheRoutes()
    {
        $this->artisan('paylidate:openapi', ['--check' => true])->assertSuccessful();
    }

    public function testAdminAndAuthenticatedOperationsDeclareTheirSecurity()
    {
        $spec = (new OpenApiCommand())->build();

        $this->assertSame([['bearerAuth' => []]], $spec['paths']['/admin/dashboard']['get']['security']);
        $this->assertArrayHasKey('403', $spec['paths']['/admin/dashboard']['get']['responses']);
        $this->assertArrayNotHasKey('security', $spec['paths']['/login']['post']);
        $this->assertArrayHasKey('429', $spec['paths']['/login']['post']['responses']);
        $this->assertSame('3.0.3', $spec['openapi']);
    }

    public function testGeneratingWritesTheFileAndCheckFailsWhenItIsStale()
    {
        $target = base_path('docs/openapi.json');
        $original = file_get_contents($target);

        try {
            file_put_contents($target, '{}');
            $this->artisan('paylidate:openapi', ['--check' => true])->assertFailed();

            $this->artisan('paylidate:openapi')->assertSuccessful();
            $this->assertSame($original, file_get_contents($target));
        } finally {
            file_put_contents($target, $original);
        }
    }
}
