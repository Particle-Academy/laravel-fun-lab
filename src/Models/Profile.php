<?php

declare(strict_types=1);

namespace LaravelFunLab\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\MorphTo;

/**
 * Profile Model
 *
 * Stores engagement profiles for any model (User, Team, etc.) with opt-in/opt-out logic,
 * display preferences, visibility settings, and aggregated engagement metrics.
 *
 * Profiles are the central entity for gamification - they receive XP (via ProfileMetrics),
 * achievements, and prizes. Any model can have a Profile by using the Awardable trait.
 *
 * @property int $id
 * @property string $awardable_type
 * @property int $awardable_id
 * @property bool $is_opted_in
 * @property array<string, mixed>|null $display_preferences
 * @property array<string, mixed>|null $visibility_settings
 * @property int $total_xp
 * @property int $achievement_count
 * @property int $prize_count
 * @property \Illuminate\Support\Carbon|null $last_activity_at
 * @property \Illuminate\Support\Carbon $created_at
 * @property \Illuminate\Support\Carbon $updated_at
 * @property-read Model $awardable
 * @property-read \Illuminate\Database\Eloquent\Collection<int, ProfileMetric> $metrics
 */
class Profile extends Model
{
    /**
     * The attributes that are mass assignable.
     *
     * @var list<string>
     */
    /**
     * Mass-assignable attributes.
     *
     * Aggregates (total_xp, achievement_count, prize_count, last_activity_at)
     * are intentionally EXCLUDED to prevent XP forgery via `Profile::create()`
     * or `update($request->all())`. Update these only via the helpers:
     * incrementXp(), incrementAchievementCount(), incrementPrizeCount(),
     * touchActivity(), recalculateAggregations(), setAggregates().
     */
    protected $fillable = [
        'awardable_type',
        'awardable_id',
        'is_opted_in',
        'display_preferences',
        'visibility_settings',
    ];

    /**
     * Default attribute values applied before save. Ensures aggregates read
     * as numeric 0 (not null) even on brand-new, unsaved Profile instances —
     * the DB migration has DEFAULT 0 but PHP-side defaults are needed for
     * code that reads aggregates before the first refresh.
     *
     * @var array<string, mixed>
     */
    protected $attributes = [
        'is_opted_in' => true,
        'total_xp' => 0,
        'achievement_count' => 0,
        'prize_count' => 0,
    ];

    /**
     * Get the table name with configurable prefix.
     */
    public function getTable(): string
    {
        return config('lfl.table_prefix', 'lfl_').'profiles';
    }

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'is_opted_in' => 'boolean',
            'display_preferences' => 'array',
            'visibility_settings' => 'array',
            'total_xp' => 'integer',
            'achievement_count' => 'integer',
            'prize_count' => 'integer',
            'last_activity_at' => 'datetime',
        ];
    }

    /**
     * Get the awardable entity (User, Team, etc.).
     *
     * @return MorphTo<Model, $this>
     */
    public function awardable(): MorphTo
    {
        return $this->morphTo();
    }

    /**
     * Get all profile metrics (XP per GamedMetric).
     *
     * @return HasMany<ProfileMetric, $this>
     */
    public function metrics(): HasMany
    {
        return $this->hasMany(ProfileMetric::class);
    }

    /**
     * Scope to filter by opt-in status.
     *
     * @param  \Illuminate\Database\Eloquent\Builder<Profile>  $query
     * @return \Illuminate\Database\Eloquent\Builder<Profile>
     */
    public function scopeOptedIn($query)
    {
        return $query->where('is_opted_in', true);
    }

    /**
     * Scope to filter by opt-out status.
     *
     * @param  \Illuminate\Database\Eloquent\Builder<Profile>  $query
     * @return \Illuminate\Database\Eloquent\Builder<Profile>
     */
    public function scopeOptedOut($query)
    {
        return $query->where('is_opted_in', false);
    }

    /**
     * Scope to filter by awardable type.
     *
     * @param  \Illuminate\Database\Eloquent\Builder<Profile>  $query
     * @return \Illuminate\Database\Eloquent\Builder<Profile>
     */
    public function scopeForAwardableType($query, string $awardableType)
    {
        return $query->where('awardable_type', $awardableType);
    }

    /**
     * Scope to order by total XP (descending).
     *
     * @param  \Illuminate\Database\Eloquent\Builder<Profile>  $query
     * @return \Illuminate\Database\Eloquent\Builder<Profile>
     */
    public function scopeOrderedByXp($query)
    {
        return $query->orderByDesc('total_xp');
    }

    /**
     * Check if the profile is opted in.
     */
    public function isOptedIn(): bool
    {
        return $this->is_opted_in;
    }

    /**
     * Check if the profile is opted out.
     */
    public function isOptedOut(): bool
    {
        return ! $this->is_opted_in;
    }

    /**
     * Opt in to gamification features.
     */
    public function optIn(): bool
    {
        return $this->update(['is_opted_in' => true]);
    }

    /**
     * Opt out of gamification features.
     */
    public function optOut(): bool
    {
        return $this->update(['is_opted_in' => false]);
    }

    /**
     * Update the last activity timestamp.
     *
     * Uses forceFill to bypass the narrowed $fillable (aggregates are no
     * longer mass-assignable — by design).
     */
    public function touchActivity(): bool
    {
        return $this->forceFill(['last_activity_at' => now()])->save();
    }

    /**
     * Calculate total XP from all profile metrics.
     *
     * @param  string|null  $metricSlug  Optional GamedMetric slug to filter by
     */
    public function calculateTotalXp(?string $metricSlug = null): int
    {
        $query = $this->metrics();

        if ($metricSlug !== null) {
            $metric = GamedMetric::findBySlug($metricSlug);
            if ($metric) {
                $query->where('gamed_metric_id', $metric->id);
            }
        }

        return (int) $query->sum('total_xp');
    }

    /**
     * Get XP for a specific GamedMetric.
     */
    public function getXpFor(string|GamedMetric $gamedMetric): int
    {
        $metric = $gamedMetric instanceof GamedMetric
            ? $gamedMetric
            : GamedMetric::findBySlug($gamedMetric);

        if (! $metric) {
            return 0;
        }

        $profileMetric = $this->metrics()->where('gamed_metric_id', $metric->id)->first();

        return $profileMetric?->total_xp ?? 0;
    }

    /**
     * Get current level for a specific GamedMetric.
     */
    public function getLevelFor(string|GamedMetric $gamedMetric): int
    {
        $metric = $gamedMetric instanceof GamedMetric
            ? $gamedMetric
            : GamedMetric::findBySlug($gamedMetric);

        if (! $metric) {
            return 1;
        }

        $profileMetric = $this->metrics()->where('gamed_metric_id', $metric->id)->first();

        return $profileMetric?->current_level ?? 1;
    }

    /**
     * Calculate achievement count from achievement grants.
     */
    public function calculateAchievementCount(): int
    {
        return AchievementGrant::where('profile_id', $this->id)->count();
    }

    /**
     * Calculate prize count from prize grants.
     */
    public function calculatePrizeCount(): int
    {
        return PrizeGrant::where('profile_id', $this->id)->count();
    }

    /**
     * Recalculate all aggregated values from related metrics, achievements, and prizes.
     *
     * Uses forceFill to bypass the narrowed $fillable (aggregates are no
     * longer mass-assignable — by design).
     */
    public function recalculateAggregations(): bool
    {
        return $this->forceFill([
            'total_xp' => $this->calculateTotalXp(),
            'achievement_count' => $this->calculateAchievementCount(),
            'prize_count' => $this->calculatePrizeCount(),
        ])->save();
    }

    /**
     * Increment total XP by the given amount.
     */
    public function incrementXp(int $amount): bool
    {
        $this->increment('total_xp', $amount);

        return true;
    }

    /**
     * Increment achievement count by 1.
     */
    public function incrementAchievementCount(): bool
    {
        $this->increment('achievement_count');

        return true;
    }

    /**
     * Increment prize count by 1.
     */
    public function incrementPrizeCount(): bool
    {
        $this->increment('prize_count');

        return true;
    }

    /**
     * Explicitly set aggregate columns, bypassing $fillable.
     *
     * Aggregates are intentionally outside of mass-assignment to prevent
     * XP forgery via untrusted request data. This helper is the sanctioned
     * API for internal services, tests, and consumer code that syncs
     * aggregates from an external source (e.g. data migration).
     *
     * @param  array<string, int|\DateTimeInterface|null>  $values
     */
    public function setAggregates(array $values): bool
    {
        $allowed = ['total_xp', 'achievement_count', 'prize_count', 'last_activity_at'];
        $filtered = array_intersect_key($values, array_flip($allowed));

        if ($filtered === []) {
            return true;
        }

        return $this->forceFill($filtered)->save();
    }
}
