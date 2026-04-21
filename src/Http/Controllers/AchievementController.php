<?php

declare(strict_types=1);

namespace LaravelFunLab\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use LaravelFunLab\Http\Resources\AchievementResource;
use LaravelFunLab\Models\Achievement;

/**
 * AchievementController
 *
 * API controller for retrieving available achievements.
 */
class AchievementController extends Controller
{
    /**
     * Get all available achievements.
     *
     * GET /achievements
     *
     * Query parameters:
     * - awardable_type: Filter by awardable type (optional)
     * - active: Filter by active status (true/false) - default: true
     */
    public function index(Request $request): AnonymousResourceCollection
    {
        $query = Achievement::query();

        if ($request->has('awardable_type')) {
            $query->forAwardableType($request->input('awardable_type'));
        }

        if ($request->has('active')) {
            if ($request->boolean('active')) {
                $query->active();
            } else {
                $query->where('is_active', false);
            }
        } else {
            $query->active();
        }

        $query->ordered();

        return AchievementResource::collection($query->get());
    }
}
