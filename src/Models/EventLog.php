<?php

declare(strict_types=1);

namespace LaravelFunLab\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\MorphTo;
use LaravelFunLab\Contracts\LflEvent;
use LaravelFunLab\Enums\AwardType;

/**
 * EventLog Model
 *
 * Captures all LFL events for analytics, debugging, and audit purposes.
 * Provides a queryable log of all award actions with full context.
 *
 * @property int $id
 * @property string $event_type Event class name or type identifier
 * @property string $award_type The type of award (points, achievement, etc.)
 * @property string $awardable_type Polymorphic type of the recipient
 * @property int $awardable_id Polymorphic ID of the recipient
 * @property int|null $award_id ID of the award/grant record
 * @property string|null $achievement_slug Achievement identifier (if applicable)
 * @property float|null $amount Amount for cumulative awards
 * @property string|null $reason Reason for the award
 * @property string|null $source Source of the award
 * @property array<string, mixed>|null $context Full event context as JSON
 * @property \Illuminate\Support\Carbon $occurred_at When the event occurred
 * @property \Illuminate\Support\Carbon $created_at
 * @property-read Model|null $awardable The recipient model
 */
class EventLog extends Model
{
    /**
     * Disable updated_at timestamp since event logs are immutable.
     */
    public const UPDATED_AT = null;

    /**
     * The attributes that are mass assignable.
     *
     * @var list<string>
     */
    protected $fillable = [
        'event_type',
        'award_type',
        'awardable_type',
        'awardable_id',
        'award_id',
        'achievement_slug',
        'amount',
        'reason',
        'source',
        'context',
        'occurred_at',
    ];

    /**
     * Get the table name with configurable prefix.
     */
    public function getTable(): string
    {
        return config('lfl.table_prefix', 'lfl_').'event_logs';
    }

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'context' => 'array',
            'amount' => 'float',
            'occurred_at' => 'datetime',
        ];
    }

    /**
     * Get the awardable entity (recipient).
     *
     * @return MorphTo<Model, $this>
     */
    public function awardable(): MorphTo
    {
        return $this->morphTo();
    }

    /**
     * Create an EventLog from an LflEvent instance.
     *
     * This is the primary factory method for creating event logs.
     */
    public static function fromEvent(LflEvent $event): self
    {
        $logData = $event->toLogArray();

        // Catalog and other system events may not have a recipient. The
        // lfl_event_logs table requires awardable_type/id NOT NULL, so fall
        // back to a sentinel that signals "no awardable was involved".
        $awardableType = $logData['recipient_type'] ?? $logData['actor_type'] ?? 'system';
        $awardableId = $logData['recipient_id'] ?? $logData['actor_id'] ?? 0;

        return static::create([
            'event_type' => $logData['event_type'],
            'award_type' => $logData['award_type'] ?? 'system',
            'awardable_type' => $awardableType,
            'awardable_id' => $awardableId,
            'award_id' => $logData['award_id'] ?? $logData['grant_id'] ?? $logData['entity_id'] ?? null,
            'achievement_slug' => $logData['achievement_slug'] ?? $logData['entity_slug'] ?? null,
            'amount' => $logData['amount'] ?? null,
            'reason' => $logData['reason'] ?? null,
            'source' => $logData['source'] ?? null,
            'context' => $logData,
            'occurred_at' => now(),
        ]);
    }

    /**
     * Scope to filter by event type.
     *
     * @param  \Illuminate\Database\Eloquent\Builder<EventLog>  $query
     * @return \Illuminate\Database\Eloquent\Builder<EventLog>
     */
    public function scopeOfEventType($query, string $eventType)
    {
        return $query->where('event_type', $eventType);
    }

    /**
     * Scope to filter by award type.
     *
     * @param  \Illuminate\Database\Eloquent\Builder<EventLog>  $query
     * @return \Illuminate\Database\Eloquent\Builder<EventLog>
     */
    public function scopeOfAwardType($query, AwardType|string $awardType)
    {
        $type = $awardType instanceof AwardType ? $awardType->value : $awardType;

        return $query->where('award_type', $type);
    }

    /**
     * Scope to filter by awardable (recipient).
     *
     * @param  \Illuminate\Database\Eloquent\Builder<EventLog>  $query
     * @return \Illuminate\Database\Eloquent\Builder<EventLog>
     */
    public function scopeForAwardable($query, Model $awardable)
    {
        return $query
            ->where('awardable_type', get_class($awardable))
            ->where('awardable_id', $awardable->getKey());
    }

    /**
     * Scope to filter by source.
     *
     * @param  \Illuminate\Database\Eloquent\Builder<EventLog>  $query
     * @return \Illuminate\Database\Eloquent\Builder<EventLog>
     */
    public function scopeFromSource($query, string $source)
    {
        return $query->where('source', $source);
    }

    /**
     * Scope to filter events within a date range.
     *
     * @param  \Illuminate\Database\Eloquent\Builder<EventLog>  $query
     * @return \Illuminate\Database\Eloquent\Builder<EventLog>
     */
    public function scopeBetween($query, $start, $end)
    {
        return $query->whereBetween('occurred_at', [$start, $end]);
    }

    /**
     * Scope to filter recent events (last N days).
     *
     * @param  \Illuminate\Database\Eloquent\Builder<EventLog>  $query
     * @return \Illuminate\Database\Eloquent\Builder<EventLog>
     */
    public function scopeRecent($query, int $days = 7)
    {
        // If days is 0, return nothing (logs older than 0 days = nothing)
        if ($days === 0) {
            return $query->whereRaw('1 = 0'); // Always false condition
        }

        return $query->where('occurred_at', '>=', now()->subDays($days));
    }

    /**
     * Scope to order by newest first.
     *
     * @param  \Illuminate\Database\Eloquent\Builder<EventLog>  $query
     * @return \Illuminate\Database\Eloquent\Builder<EventLog>
     */
    public function scopeLatest($query)
    {
        return $query->orderByDesc('occurred_at');
    }
}
