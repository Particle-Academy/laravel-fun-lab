<?php

declare(strict_types=1);

namespace LaravelFunLab\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use LaravelFunLab\Facades\LFL;
use LaravelFunLab\Http\Resources\LeaderboardEntryResource;
use LaravelFunLab\Models\GamedMetric;

/**
 * LeaderboardController
 *
 * API controller for retrieving leaderboard data by awardable type.
 */
class LeaderboardController extends Controller
{
    /**
     * Get leaderboard data for a specific awardable type.
     *
     * GET /leaderboards/{type}
     *
     * Query parameters:
     * - by: Sort metric ('xp', 'achievements', 'prizes') - default: 'xp'
     * - metric: GamedMetric slug to rank on instead of the profile-wide total - optional
     * - period: Time period ('daily', 'weekly', 'monthly', 'all-time') - default: 'all-time'
     * - per_page: Items per page - default: 15
     * - page: Page number - default: 1
     */
    public function index(string $type, Request $request): AnonymousResourceCollection
    {
        $request->validate([
            'metric' => ['sometimes', 'nullable', 'string', 'max:255', Rule::exists((new GamedMetric)->getTable(), 'slug')],
        ]);

        $builder = LFL::leaderboard()
            ->for($type)
            ->by($request->input('by', 'xp'))
            ->period($request->input('period', 'all-time'))
            ->perPage((int) $request->input('per_page', 15))
            ->page((int) $request->input('page', 1));

        $metric = $request->input('metric');
        if ($metric !== null && $metric !== '') {
            // `metric()` is not on LeaderboardServiceContract (adding it would
            // break every custom implementation), so a swapped-in class may not
            // have it. Say so rather than silently ignoring the filter.
            if (! method_exists($builder, 'metric')) {
                throw ValidationException::withMessages([
                    'metric' => 'The configured leaderboard service does not support per-metric leaderboards.',
                ]);
            }
            $builder->metric($metric);
        }

        return LeaderboardEntryResource::collection($builder->paginate());
    }
}
