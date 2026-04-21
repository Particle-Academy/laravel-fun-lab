<?php

declare(strict_types=1);

namespace LaravelFunLab\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use LaravelFunLab\Models\MetricLevel;

/**
 * @mixin MetricLevel
 */
class MetricLevelResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'gamed_metric_id' => $this->gamed_metric_id,
            'gamed_metric_slug' => $this->gamedMetric?->slug,
            'level' => (int) $this->level,
            'xp_threshold' => (int) $this->xp_threshold,
            'name' => $this->name,
            'description' => $this->description,
            'created_at' => $this->created_at?->toIso8601String(),
            'updated_at' => $this->updated_at?->toIso8601String(),
        ];
    }
}
