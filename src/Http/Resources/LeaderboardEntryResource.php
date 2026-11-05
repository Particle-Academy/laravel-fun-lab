<?php

declare(strict_types=1);

namespace LaravelFunLab\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use LaravelFunLab\Models\Profile;

/**
 * @mixin Profile
 */
class LeaderboardEntryResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'rank' => $this->rank ?? null,
            'id' => $this->id,
            'awardable_type' => $this->awardable_type,
            'awardable_id' => $this->awardable_id,
            'total_xp' => (int) $this->total_xp,
            'achievement_count' => (int) $this->achievement_count,
            'prize_count' => (int) $this->prize_count,
            'last_activity_at' => $this->last_activity_at?->toIso8601String(),
            // Present only on a per-metric board (`?metric=`).
            'metric' => $this->when($this->metric_slug !== null, fn () => [
                'slug' => $this->metric_slug,
                'total_xp' => (int) $this->metric_xp,
                'current_level' => (int) $this->metric_level,
            ]),
            // Present only on a period board: the score the entry is ranked on.
            'period_xp' => $this->when($this->period_xp !== null, fn () => (int) $this->period_xp),
        ];
    }
}
