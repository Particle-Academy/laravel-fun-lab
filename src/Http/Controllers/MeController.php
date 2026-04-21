<?php

declare(strict_types=1);

namespace LaravelFunLab\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use LaravelFunLab\Http\Resources\AchievementResource;
use LaravelFunLab\Http\Resources\AwardResource;
use LaravelFunLab\Http\Resources\ProfileResource;
use LaravelFunLab\Models\AchievementGrant;
use LaravelFunLab\Models\ProfileMetric;

/**
 * MeController
 *
 * Convenience endpoints resolving the authenticated user (via the
 * consumer-configured auth middleware) to their Profile without needing
 * {type}/{id} path params. The authenticated user model must use the
 * Awardable trait; otherwise 422 is returned.
 */
class MeController extends Controller
{
    public function profile(Request $request): JsonResponse|ProfileResource
    {
        $user = $this->requireAwardable($request);
        if ($user instanceof JsonResponse) {
            return $user;
        }

        return new ProfileResource($user->getProfile());
    }

    public function achievements(Request $request): JsonResponse|AnonymousResourceCollection
    {
        $user = $this->requireAwardable($request);
        if ($user instanceof JsonResponse) {
            return $user;
        }

        $profile = $user->getProfile();

        $achievements = AchievementGrant::query()
            ->where('profile_id', $profile->id)
            ->with('achievement')
            ->get()
            ->map(fn (AchievementGrant $grant) => $grant->achievement)
            ->filter()
            ->values();

        return AchievementResource::collection($achievements);
    }

    public function awards(Request $request): JsonResponse
    {
        $user = $this->requireAwardable($request);
        if ($user instanceof JsonResponse) {
            return $user;
        }

        $profile = $user->getProfile();

        $metrics = ProfileMetric::query()
            ->where('profile_id', $profile->id)
            ->with('gamedMetric')
            ->orderByDesc('total_xp')
            ->paginate((int) $request->input('per_page', 15));

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
        ]);
    }

    /**
     * Resolve the authenticated user, ensuring they use the Awardable trait.
     *
     * @return \Illuminate\Database\Eloquent\Model|JsonResponse
     */
    protected function requireAwardable(Request $request)
    {
        $user = $request->user();

        if ($user === null) {
            return response()->json(['message' => 'Unauthenticated.'], 401);
        }

        if (! method_exists($user, 'getProfile')) {
            return response()->json([
                'message' => 'The authenticated model does not use the Awardable trait.',
            ], 422);
        }

        return $user;
    }
}
