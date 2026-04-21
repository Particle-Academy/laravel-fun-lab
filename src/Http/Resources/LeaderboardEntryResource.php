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
        ];
    }
}
