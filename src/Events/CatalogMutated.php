<?php

declare(strict_types=1);

namespace LaravelFunLab\Events;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;
use LaravelFunLab\Contracts\LflEvent;
use LaravelFunLab\Enums\AwardType;

/**
 * CatalogMutated
 *
 * Dispatched by AwardEngine::setup() whenever a catalog entry (GamedMetric,
 * MetricLevel, MetricLevelGroup, Achievement, Prize) is created or updated
 * via LFL::setup(). Enables audit-trail logging for high-privilege
 * administrative mutations.
 */
class CatalogMutated implements LflEvent
{
    use Dispatchable, SerializesModels;

    /**
     * @param  string  $entityType  One of: gamed-metric, metric-level, metric-level-group, metric-level-group-level, metric-level-group-metric, achievement, prize
     * @param  Model  $model  The model that was created or updated
     * @param  bool  $wasCreated  True if the model was newly created, false if updated
     * @param  Model|null  $actor  The user performing the mutation (null for programmatic)
     */
    public function __construct(
        public string $entityType,
        public Model $model,
        public bool $wasCreated,
        public ?Model $actor = null,
    ) {}

    public function getAwardType(): AwardType
    {
        return AwardType::Achievement;
    }

    public function getRecipient(): Model
    {
        return $this->actor ?? $this->model;
    }

    public function getReason(): ?string
    {
        return null;
    }

    public function getSource(): ?string
    {
        return 'catalog-mutation';
    }

    /**
     * @return array<string, mixed>
     */
    public function toLogArray(): array
    {
        return [
            'event_type' => 'catalog_mutated',
            'entity_type' => $this->entityType,
            'entity_model' => get_class($this->model),
            'entity_id' => $this->model->getKey(),
            'entity_slug' => $this->model->slug ?? null,
            'was_created' => $this->wasCreated,
            'actor_type' => $this->actor ? get_class($this->actor) : null,
            'actor_id' => $this->actor?->getKey(),
            'occurred_at' => now()->toIso8601String(),
        ];
    }
}
