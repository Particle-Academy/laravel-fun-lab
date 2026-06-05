<?php

declare(strict_types=1);

namespace LaravelFunLab\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * Achievement Model
 *
 * Defines achievement types with name, description, icon, and metadata.
 * Achievements can be targeted to specific awardable types or be universal.
 *
 * @property int $id
 * @property string $slug
 * @property string $name
 * @property string|null $description
 * @property string|null $icon
 * @property string|null $awardable_type Target model type (null = universal)
 * @property array<string, mixed>|null $meta
 * @property bool $is_active
 * @property bool $is_hidden
 * @property int $sort_order
 * @property Carbon $created_at
 * @property Carbon $updated_at
 * @property-read Collection<int, AchievementGrant> $grants
 */
class Achievement extends Model
{
    /**
     * The attributes that are mass assignable.
     *
     * @var list<string>
     */
    protected $fillable = [
        'slug',
        'name',
        'description',
        'icon',
        'awardable_type',
        'meta',
        'is_active',
        'is_hidden',
        'sort_order',
    ];

    /**
     * Get the table name with configurable prefix.
     */
    public function getTable(): string
    {
        return config('lfl.table_prefix', 'lfl_').'achievements';
    }

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'meta' => 'array',
            'is_active' => 'boolean',
            'is_hidden' => 'boolean',
            'sort_order' => 'integer',
        ];
    }

    /**
     * Get all grants for this achievement.
     *
     * @return HasMany<AchievementGrant, $this>
     */
    public function grants(): HasMany
    {
        return $this->hasMany(AchievementGrant::class);
    }

    /**
     * Get all MetricLevels this achievement is attached to.
     *
     * @return BelongsToMany<MetricLevel, $this>
     */
    public function metricLevels(): BelongsToMany
    {
        return $this->belongsToMany(
            MetricLevel::class,
            config('lfl.table_prefix', 'lfl_').'achievement_metric_levels',
            'achievement_id',
            'metric_level_id'
        );
    }

    /**
     * Get all MetricLevelGroupLevels this achievement is attached to.
     *
     * @return BelongsToMany<MetricLevelGroupLevel, $this>
     */
    public function metricLevelGroupLevels(): BelongsToMany
    {
        return $this->belongsToMany(
            MetricLevelGroupLevel::class,
            config('lfl.table_prefix', 'lfl_').'achievement_metric_level_group_levels',
            'achievement_id',
            'metric_level_group_level_id'
        );
    }

    /**
     * Get all prizes attached to this achievement.
     *
     * @return BelongsToMany<Prize, $this>
     */
    public function prizes(): BelongsToMany
    {
        return $this->belongsToMany(
            Prize::class,
            config('lfl.table_prefix', 'lfl_').'achievement_prizes',
            'achievement_id',
            'prize_id'
        );
    }

    /**
     * Scope to only active achievements.
     *
     * @param  Builder<Achievement>  $query
     * @return Builder<Achievement>
     */
    public function scopeActive($query)
    {
        return $query->where('is_active', true);
    }

    /**
     * Scope to only NON-hidden achievements — the public catalog. Hidden
     * ("secret"/Easter-egg) achievements are excluded until earned; combine
     * with a query of the awardable's earned grants to reveal earned ones.
     *
     * @param  Builder<Achievement>  $query
     * @return Builder<Achievement>
     */
    public function scopeVisible($query)
    {
        return $query->where('is_hidden', false);
    }

    /**
     * Scope to only hidden ("secret") achievements.
     *
     * @param  Builder<Achievement>  $query
     * @return Builder<Achievement>
     */
    public function scopeHidden($query)
    {
        return $query->where('is_hidden', true);
    }

    /**
     * Scope to filter by awardable type.
     *
     * @param  Builder<Achievement>  $query
     * @return Builder<Achievement>
     */
    public function scopeForAwardableType($query, ?string $awardableType)
    {
        return $query->where(function ($q) use ($awardableType) {
            $q->whereNull('awardable_type')
                ->orWhere('awardable_type', $awardableType);
        });
    }

    /**
     * Scope to order by sort order.
     *
     * @param  Builder<Achievement>  $query
     * @return Builder<Achievement>
     */
    public function scopeOrdered($query)
    {
        return $query->orderBy('sort_order');
    }

    /**
     * Find an achievement by slug.
     */
    public static function findBySlug(string $slug): ?self
    {
        return static::where('slug', $slug)->first();
    }
}
