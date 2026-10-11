<?php

namespace Tests\Feature;

use Illuminate\Support\Facades\Route;
use Tests\TestCase;

class ContractTest extends TestCase
{
    public function test_openapi_documents_exactly_the_implemented_api_routes(): void
    {
        $spec = json_decode(file_get_contents(base_path('openapi.json')), true, flags: JSON_THROW_ON_ERROR);
        $actual = [];
        foreach (Route::getRoutes() as $route) {
            if (! str_starts_with($route->uri(), 'api/v1/')) {
                continue;
            }
            $paths = [substr($route->uri(), 6)];
            if (str_contains($route->uri(), '{kind}')) {
                $paths = array_map(fn ($kind) => str_replace('{kind}', $kind, $paths[0]), ['distribution', 'numeric-summary', 'crosstab']);
            }
            foreach ($paths as $path) {
                foreach ($route->methods() as $method) {
                    if ($method !== 'HEAD') {
                        $actual[] = strtolower($method).' '.$path;
                    }
                }
            }
        }
        $documented = [];
        foreach ($spec['paths'] as $path => $methods) {
            foreach (array_keys($methods) as $method) {
                $documented[] = $method.' '.$path;
            }
        }
        sort($actual);
        sort($documented);
        $this->assertSame($documented, $actual);
        $walk = function ($value) use (&$walk, $spec) {
            if (! is_array($value)) {
                return;
            }
            if (isset($value['$ref'])) {
                $node = $spec;
                foreach (explode('/', substr($value['$ref'], 2)) as $segment) {
                    $this->assertArrayHasKey($segment, $node);
                    $node = $node[$segment];
                }
            }
            foreach ($value as $child) {
                $walk($child);
            }
        };
        $walk($spec);
    }
}
