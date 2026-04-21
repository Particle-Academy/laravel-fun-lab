<?php

declare(strict_types=1);

namespace LaravelFunLab\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use LaravelFunLab\Facades\LFL;
use LaravelFunLab\Http\Concerns\AuthorizesLflActions;
use LaravelFunLab\Http\Resources\MetricLevelResource;
use LaravelFunLab\Models\GamedMetric;
use LaravelFunLab\Models\MetricLevel;

class MetricLevelController extends Controller
{
    use AuthorizesLflActions;

    public function index(string $metric): JsonResponse|AnonymousResourceCollection
    {
        $gamedMetric = GamedMetric::findBySlug($metric);
        if ($gamedMetric === null) {
            return response()->json([
                'message' => "GamedMetric '{$metric}' not found.",
            ], 404);
        }

        $levels = MetricLevel::query()
            ->with('gamedMetric')
            ->forMetric($gamedMetric->id)
            ->ordered()
            ->get();

        return MetricLevelResource::collection($levels);
    }

    public function store(Request $request): JsonResponse|MetricLevelResource
    {
        $data = $request->validate([
            'metric' => ['required', 'string', 'max:255'],
            'level' => ['required', 'integer', 'min:1'],
            'xp' => ['required', 'integer', 'min:0'],
            'name' => ['sometimes', 'nullable', 'string', 'max:255'],
            'description' => ['sometimes', 'nullable', 'string'],
        ]);

        $authDenied = $this->authorizeAction($request, 'setup', [
            'entity_type' => 'metric-level',
            'slug' => $data['metric'] ?? null,
        ]);
        if ($authDenied !== null) {
            return $authDenied;
        }

        $level = LFL::setup('metric-level', $data);

        return new MetricLevelResource($level->loadMissing('gamedMetric'));
    }
}
