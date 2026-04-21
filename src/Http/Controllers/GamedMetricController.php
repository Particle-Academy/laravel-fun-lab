<?php

declare(strict_types=1);

namespace LaravelFunLab\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use LaravelFunLab\Facades\LFL;
use LaravelFunLab\Http\Concerns\AuthorizesLflActions;
use LaravelFunLab\Http\Resources\GamedMetricResource;
use LaravelFunLab\Models\GamedMetric;

class GamedMetricController extends Controller
{
    use AuthorizesLflActions;

    public function index(Request $request): AnonymousResourceCollection
    {
        $query = GamedMetric::query();

        if ($request->has('active')) {
            if ($request->boolean('active')) {
                $query->active();
            } else {
                $query->where('active', false);
            }
        }

        return GamedMetricResource::collection($query->orderBy('name')->get());
    }

    public function store(Request $request): JsonResponse|GamedMetricResource
    {
        $data = $request->validate([
            'slug' => ['required', 'string', 'max:255'],
            'name' => ['sometimes', 'string', 'max:255'],
            'description' => ['sometimes', 'nullable', 'string'],
            'icon' => ['sometimes', 'nullable', 'string', 'max:255'],
            'active' => ['sometimes', 'boolean'],
        ]);

        $authDenied = $this->authorizeAction($request, 'setup', [
            'entity_type' => 'gamed-metric',
            'slug' => $data['slug'] ?? null,
        ]);
        if ($authDenied !== null) {
            return $authDenied;
        }

        $metric = LFL::setup('gamed-metric', $data);

        return new GamedMetricResource($metric);
    }
}
