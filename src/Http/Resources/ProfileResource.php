<?php

declare(strict_types=1);

namespace LaravelFunLab\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use LaravelFunLab\Models\Profile;

/**
 * @mixin Profile
 */
class ProfileResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'awardable_type' => $this->awardable_type,
            'awardable_id' => $this->awardable_id,
            'is_opted_in' => (bool) $this->is_opted_in,
            'display_preferences' => $this->display_preferences,
            'visibility_settings' => $this->visibility_settings,
            'total_xp' => (int) $this->total_xp,
            'achievement_count' => (int) $this->achievement_count,
            'prize_count' => (int) $this->prize_count,
            'last_activity_at' => $this->last_activity_at?->toIso8601String(),
            'created_at' => $this->created_at?->toIso8601String(),
            'updated_at' => $this->updated_at?->toIso8601String(),
        ];
    }
}
