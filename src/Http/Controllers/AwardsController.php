<?php

declare(strict_types=1);

namespace LaravelFunLab\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use LaravelFunLab\Http\Resources\AwardResource;
use LaravelFunLab\Http\Resources\ProfileResource;
use LaravelFunLab\Models\Profile;
use LaravelFunLab\Models\ProfileMetric;

/**
 * AwardsController
 *
 * API controller for retrieving XP metrics for awardable entities.
 * Returns ProfileMetrics (XP per GamedMetric) for a given profile.
 */
class AwardsController extends Controller
{
    /**
     * Get XP metrics for a specific awardable entity.
     *
     * GET /awards/{type}/{id}
     *
     * Query parameters:
     * - metric_slug: Filter by GamedMetric slug - optional
     * - per_page: Items per page - default: 15
     * - page: Page number - default: 1
     */
    public function index(string $type, int|string $id, Request $request): JsonResponse
    {
        $profile = Profile::query()
            ->where('awardable_type', $type)
            ->where('awardable_id', $id)
            ->first();

        if (! $profile) {
            return response()->json([
                'data' => [],
                'profile' => null,
                'meta' => ['total' => 0],
            ]);
        }

        $query = ProfileMetric::query()
            ->where('profile_id', $profile->id)
            ->with('gamedMetric')
            ->orderByDesc('total_xp');

        if ($request->has('metric_slug')) {
            $query->whereHas('gamedMetric', function ($q) use ($request) {
                $q->where('slug', $request->input('metric_slug'));
            });
        }

        $metrics = $query->paginate((int) $request->input('per_page', 15));

        return response()->json([
            'data' => AwardResource::collection($metrics->items())->toArray($request),
            'profile' => (new ProfileResource($profile))->toArray($request),
            'meta' => [
                'current_page' => $metrics->currentPage(),
                'from' => $metrics->firstItem(),
                'last_page' => $metrics->lastPage(),
                'per_page' => $metrics->perPage(),
                'to' => $metrics->lastItem(),
                'total' => $metrics->total(),
            ],
            'links' => [
                'first' => $metrics->url(1),
                'last' => $metrics->url($metrics->lastPage()),
                'prev' => $metrics->previousPageUrl(),
                'next' => $metrics->nextPageUrl(),
            ],
        ]);
    }
}
