<?php

declare(strict_types=1);

namespace LaravelFunLab\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use LaravelFunLab\Facades\LFL;
use LaravelFunLab\Http\Resources\LeaderboardEntryResource;

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
     * - period: Time period ('daily', 'weekly', 'monthly', 'all-time') - default: 'all-time'
     * - per_page: Items per page - default: 15
     * - page: Page number - default: 1
     */
    public function index(string $type, Request $request): AnonymousResourceCollection
    {
        $leaderboard = LFL::leaderboard()
            ->for($type)
            ->by($request->input('by', 'xp'))
            ->period($request->input('period', 'all-time'))
            ->perPage((int) $request->input('per_page', 15))
            ->page((int) $request->input('page', 1))
            ->paginate();

        return LeaderboardEntryResource::collection($leaderboard);
    }
}
