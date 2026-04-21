<?php

declare(strict_types=1);

namespace LaravelFunLab\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Symfony\Component\Yaml\Yaml;

/**
 * OpenApiController
 *
 * Serves the committed OpenAPI 3.1 spec as JSON at GET /openapi.json.
 * The spec itself lives at packages/laravel-fun-lab/resources/openapi.yaml
 * and is the hand-rolled source of truth for the REST API.
 */
class OpenApiController extends Controller
{
    public function show(): JsonResponse
    {
        $path = __DIR__.'/../../../resources/openapi.yaml';

        if (! file_exists($path)) {
            return response()->json([
                'message' => 'OpenAPI spec not found.',
            ], 500);
        }

        if (class_exists(Yaml::class)) {
            $spec = Yaml::parseFile($path);

            return response()->json($spec);
        }

        // Fallback: ship the raw YAML as text for consumers that can parse it.
        return response()->json([
            'message' => 'YAML parser not available (install symfony/yaml to serve as JSON).',
            'raw' => file_get_contents($path),
        ], 501);
    }
}
