<?php

declare(strict_types=1);

namespace LaravelFunLab\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use LaravelFunLab\Models\Prize;

/**
 * @mixin Prize
 */
class PrizeResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'slug' => $this->slug,
            'name' => $this->name,
            'description' => $this->description,
            'type' => $this->type?->value,
            'cost_in_points' => (float) $this->cost_in_points,
            'inventory_quantity' => $this->inventory_quantity,
            'remaining_inventory' => $this->getRemainingInventory(),
            'meta' => $this->meta,
            'is_active' => (bool) $this->is_active,
            'sort_order' => (int) $this->sort_order,
            'created_at' => $this->created_at?->toIso8601String(),
            'updated_at' => $this->updated_at?->toIso8601String(),
        ];
    }
}
