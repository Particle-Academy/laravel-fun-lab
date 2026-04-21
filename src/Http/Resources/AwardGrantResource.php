<?php

declare(strict_types=1);

namespace LaravelFunLab\Http\Resources;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use LaravelFunLab\Models\AchievementGrant;
use LaravelFunLab\Models\PrizeGrant;

/**
 * Unified resource for Achievement or Prize grants.
 *
 * The inner $resource may be an AchievementGrant or PrizeGrant; the grant_type
 * field disambiguates, and related fields are emitted per type.
 *
 * @mixin Model
 */
class AwardGrantResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        if ($this->resource instanceof AchievementGrant) {
            return [
                'grant_type' => 'achievement',
                'id' => $this->id,
                'profile_id' => $this->profile_id,
                'achievement_id' => $this->achievement_id,
                'achievement_slug' => $this->achievement?->slug,
                'achievement_name' => $this->achievement?->name,
                'reason' => $this->reason,
                'source' => $this->source,
                'meta' => $this->meta,
                'granted_at' => $this->created_at?->toIso8601String(),
            ];
        }

        if ($this->resource instanceof PrizeGrant) {
            return [
                'grant_type' => 'prize',
                'id' => $this->id,
                'profile_id' => $this->profile_id,
                'prize_id' => $this->prize_id,
                'prize_slug' => $this->prize?->slug,
                'prize_name' => $this->prize?->name,
                'status' => $this->status,
                'reason' => $this->reason,
                'source' => $this->source,
                'meta' => $this->meta,
                'granted_at' => $this->created_at?->toIso8601String(),
            ];
        }

        return [
            'grant_type' => 'unknown',
            'id' => $this->id ?? null,
        ];
    }
}
