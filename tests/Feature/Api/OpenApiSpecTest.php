<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Route;
use Symfony\Component\Yaml\Yaml;

/*
|--------------------------------------------------------------------------
| OpenAPI Spec Contract Tests
|--------------------------------------------------------------------------
|
| Asserts that every route defined in routes/api.php has a matching path in
| resources/openapi.yaml, and vice versa. Prevents silent drift between the
| hand-rolled spec and the code.
|
*/

beforeEach(function () {
    config(['lfl.api.enabled' => true]);
    config(['lfl.api.writes' => true]);
    config(['lfl.api.auth.middleware' => null]);
});

function normalizeRoutePath(string $uri): string
{
    // Strip the api/lfl prefix; keep the rest with {param} placeholders preserved.
    return '/'.ltrim(str_replace('api/lfl', '', $uri), '/');
}

function lflRoutes(): array
{
    $routes = [];

    foreach (Route::getRoutes()->getRoutes() as $route) {
        $uri = $route->uri();
        if (! str_starts_with($uri, 'api/lfl')) {
            continue;
        }
        $path = normalizeRoutePath($uri);
        foreach ($route->methods() as $method) {
            if (in_array($method, ['HEAD'], true)) {
                continue;
            }
            $routes[] = strtolower($method).' '.$path;
        }
    }

    return $routes;
}

function openApiSpec(): array
{
    $path = __DIR__.'/../../../resources/openapi.yaml';
    expect(file_exists($path))->toBeTrue('OpenAPI spec file must exist');

    return Yaml::parseFile($path);
}

function openApiEndpoints(array $spec): array
{
    $endpoints = [];
    foreach ($spec['paths'] ?? [] as $path => $ops) {
        foreach ($ops as $method => $_op) {
            if (! in_array($method, ['get', 'post', 'put', 'patch', 'delete'], true)) {
                continue;
            }
            $endpoints[] = $method.' '.$path;
        }
    }

    return $endpoints;
}

it('registers api.php routes', function () {
    expect(count(lflRoutes()))->toBeGreaterThan(0);
});

it('has a spec entry for every registered route', function () {
    $spec = openApiSpec();
    $endpoints = openApiEndpoints($spec);

    $missing = [];
    foreach (lflRoutes() as $route) {
        if (! in_array($route, $endpoints, true)) {
            $missing[] = $route;
        }
    }

    expect($missing)->toBe([], 'Routes without matching OpenAPI spec entry: '.implode(', ', $missing));
});

it('has a registered route for every spec entry', function () {
    $spec = openApiSpec();
    $endpoints = openApiEndpoints($spec);
    $routes = lflRoutes();

    $missing = [];
    foreach ($endpoints as $endpoint) {
        if (! in_array($endpoint, $routes, true)) {
            $missing[] = $endpoint;
        }
    }

    expect($missing)->toBe([], 'Spec entries without matching route: '.implode(', ', $missing));
});

it('serves the spec as JSON at /api/lfl/openapi.json', function () {
    $response = $this->getJson('/api/lfl/openapi.json');

    $response->assertSuccessful();
    expect($response->json('openapi'))->toBe('3.0.3');
    expect($response->json('info.title'))->toBe('Laravel Fun Lab REST API');
});
