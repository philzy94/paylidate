<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Routing\Route;
use Illuminate\Support\Facades\Route as RouteFacade;

/**
 * Writes docs/openapi.json from the registered routes and resources/openapi/summaries.php,
 * so the published API description cannot drift from the code.
 */
class OpenApiCommand extends Command
{
    protected $signature = 'paylidate:openapi {--check : Fail if docs/openapi.json is out of date}';

    protected $description = 'Generate the OpenAPI description of the API';

    public function handle(): int
    {
        $json = json_encode($this->build(), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR) . "\n";
        $target = base_path('docs/openapi.json');

        if ($this->option('check')) {
            $current = is_file($target) ? (string) file_get_contents($target) : '';
            if ($current !== $json) {
                $this->error('docs/openapi.json is out of date. Run: php artisan paylidate:openapi');

                return self::FAILURE;
            }
            $this->info('docs/openapi.json is up to date.');

            return self::SUCCESS;
        }

        file_put_contents($target, $json);
        $this->info('Wrote docs/openapi.json');

        return self::SUCCESS;
    }

    /** @return array<string, mixed> */
    public function build(): array
    {
        $summaries = self::summaries();
        $paths = [];

        foreach (self::apiRoutes() as $route) {
            $method = strtolower($route->methods()[0]);
            $key = strtoupper($method) . ' ' . $route->uri();
            [$summary, $tag] = $summaries[$key] ?? ['', 'Other'];

            $paths['/' . substr($route->uri(), 4)][$method] = $this->operation($route, $summary, $tag);
        }
        ksort($paths);

        return [
            'openapi' => '3.0.3',
            'info' => [
                'title' => 'Paylidate API',
                'version' => $this->version(),
                'description' => 'Escrow and payments API. See docs/ARCHITECTURE.md for the model behind it.',
                'license' => ['name' => 'MIT', 'url' => 'https://opensource.org/licenses/MIT'],
            ],
            'servers' => [['url' => '/api']],
            'paths' => $paths,
            'components' => [
                'securitySchemes' => ['bearerAuth' => ['type' => 'http', 'scheme' => 'bearer']],
                'schemas' => ['Error' => [
                    'type' => 'object',
                    'properties' => [
                        'status' => ['type' => 'boolean', 'example' => false],
                        'message' => ['type' => 'string'],
                        'code' => ['type' => 'string', 'example' => 'forbidden'],
                    ],
                ]],
            ],
        ];
    }

    /** @return array<string, array{0: string, 1: string}> */
    public static function summaries(): array
    {
        /** @var array<string, array{0: string, 1: string}> $summaries */
        $summaries = require resource_path('openapi/summaries.php');

        return $summaries;
    }

    /** @return list<Route> one route per method+uri, HEAD excluded */
    public static function apiRoutes(): array
    {
        $routes = [];
        foreach (RouteFacade::getRoutes()->getRoutes() as $route) {
            if (str_starts_with($route->uri(), 'api/')) {
                $routes[] = $route;
            }
        }

        return $routes;
    }

    /** @return array<string, mixed> */
    private function operation(Route $route, string $summary, string $tag): array
    {
        $middleware = $route->gatherMiddleware();
        $operation = [
            'summary' => $summary,
            'tags' => [$tag],
            'operationId' => $this->operationId($route),
            'responses' => ['200' => ['description' => 'Success']] + $this->errorResponses($route, $middleware),
        ];

        $parameters = array_map(
            fn (string $name) => ['name' => $name, 'in' => 'path', 'required' => true, 'schema' => ['type' => 'string']],
            $route->parameterNames()
        );
        if ($parameters) {
            $operation['parameters'] = $parameters;
        }
        if (in_array('auth:api', $middleware, true)) {
            $operation['security'] = [['bearerAuth' => []]];
        }
        $throttle = $this->throttle($middleware);
        if ($throttle !== null) {
            $operation['description'] = 'Rate limited (' . $throttle . ').';
        }

        return $operation;
    }

    /**
     * @param array<int, string> $middleware
     * @return array<int, array<string, mixed>>
     */
    private function errorResponses(Route $route, array $middleware): array
    {
        $responses = [];
        if (in_array('auth:api', $middleware, true)) {
            $responses['401'] = $this->errorResponse('Unauthenticated');
        }
        if (in_array('admin', $middleware, true)) {
            $responses['403'] = $this->errorResponse('Not an administrator');
        }
        if ($this->throttle($middleware) !== null) {
            $responses['429'] = $this->errorResponse('Rate limited');
        }
        if (array_intersect($route->methods(), ['POST', 'PUT'])) {
            $responses['422'] = $this->errorResponse('Validation or provider failure');
        }
        ksort($responses);

        return $responses;
    }

    /** @param array<int, string> $middleware */
    private function throttle(array $middleware): ?string
    {
        foreach ($middleware as $name) {
            if (str_starts_with($name, 'throttle:')) {
                return $name;
            }
        }

        return null;
    }

    /** @return array<string, mixed> */
    private function errorResponse(string $description): array
    {
        return [
            'description' => $description,
            'content' => ['application/json' => ['schema' => ['$ref' => '#/components/schemas/Error']]],
        ];
    }

    private function operationId(Route $route): string
    {
        $parts = preg_split('/[^A-Za-z0-9]+/', strtolower($route->methods()[0]) . '/' . substr($route->uri(), 4)) ?: [];

        return lcfirst(implode('', array_map('ucfirst', array_filter($parts))));
    }

    private function version(): string
    {
        preg_match('/^## \[(\d+\.\d+\.\d+)\]/m', (string) file_get_contents(base_path('CHANGELOG.md')), $m);

        return $m[1] ?? '0.0.0';
    }
}
