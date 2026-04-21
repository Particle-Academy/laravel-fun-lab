<?php

declare(strict_types=1);

namespace LaravelFunLab\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use LaravelFunLab\Models\ProfileMetric;

/**
 * Represents XP awarded to a profile for a given GamedMetric.
 *
 * @mixin ProfileMetric
 */
class AwardResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'profile_id' => $this->profile_id,
            'gamed_metric_id' => $this->gamed_metric_id,
            'gamed_metric_slug' => $this->gamedMetric?->slug,
            'gamed_metric_name' => $this->gamedMetric?->name,
            'total_xp' => (int) $this->total_xp,
            'current_level' => (int) $this->current_level,
            'created_at' => $this->created_at?->toIso8601String(),
            'updated_at' => $this->updated_at?->toIso8601String(),
        ];
    }
}
