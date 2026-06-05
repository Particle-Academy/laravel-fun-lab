<?php

declare(strict_types=1);

namespace LaravelFunLab\Services;

use Illuminate\Contracts\Foundation\Application;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;
use Illuminate\Support\Traits\Macroable;
use InvalidArgumentException;
use LaravelFunLab\Contracts\AnalyticsServiceContract;
use LaravelFunLab\Contracts\AwardEngineContract;
use LaravelFunLab\Contracts\LeaderboardServiceContract;
use LaravelFunLab\Events\AchievementUnlocked;
use LaravelFunLab\Events\AwardGranted;
use LaravelFunLab\Events\CatalogMutated;
use LaravelFunLab\Events\PrizeAwarded;
use LaravelFunLab\Models\Achievement;
use LaravelFunLab\Models\AchievementGrant;
use LaravelFunLab\Models\GamedMetric;
use LaravelFunLab\Models\MetricLevel;
use LaravelFunLab\Models\MetricLevelGroup;
use LaravelFunLab\Models\MetricLevelGroupLevel;
use LaravelFunLab\Models\MetricLevelGroupMetric;
use LaravelFunLab\Models\Prize;
use LaravelFunLab\Models\PrizeGrant;
use LaravelFunLab\Models\Profile;
use LaravelFunLab\Validation\SetupValidator;
use LaravelFunLab\ValueObjects\AwardResult;

/**
 * AwardEngine Service
 *
 * The core service that powers the LFL facade. Provides a minimal, focused API
 * for all gamification operations:
 *
 * - LFL::setup() - Create any entity (GamedMetric, MetricLevel, MetricLevelGroup, Achievement, Prize)
 * - LFL::award() - Award XP to a GamedMetric
 * - LFL::grant() - Grant an Achievement or Prize
 * - LFL::hasLevel() - Check if a Profile has reached a level in a metric or group
 *
 * Macros can be registered to extend functionality:
 * - AwardEngine::macro('customMethod', fn() => ...)
 * - LFL::customMethod()
 */
class AwardEngine implements AwardEngineContract
{
    use Macroable;

    public function __construct(
        protected Application $app,
        protected GamedMetricService $gamedMetricService,
        protected MetricLevelService $metricLevelService,
        protected MetricLevelGroupService $metricLevelGroupService
    ) {}

    /**
     * Set up a new entity dynamically at runtime.
     *
     * Creates GamedMetrics, MetricLevels, MetricLevelGroups, Achievements, or Prizes.
     * Uses upsert logic to create new entities or update existing ones by slug.
     *
     * @param  string  $a  Entity type: 'gamed-metric', 'metric-level', 'metric-level-group', 'metric-level-group-level', 'achievement', 'prize'
     * @param  array<string, mixed>  $with  Configuration array with entity-specific fields
     * @return Model The created or updated entity instance
     *
     * @example LFL::setup(a: 'achievement', with: ['slug' => 'first-login', 'description' => 'Logged in for the first time'])
     * @example LFL::setup(a: 'gamed-metric', with: ['slug' => 'combat-xp', 'name' => 'Combat XP'])
     * @example LFL::setup(a: 'metric-level', with: ['metric' => 'combat-xp', 'level' => 1, 'xp' => 100, 'name' => 'Novice Fighter'])
     * @example LFL::setup(a: 'prize', with: ['slug' => 'premium-access', 'name' => '1 Month Premium', 'type' => 'virtual'])
     */
    public function setup(string $a, array $with = []): Model
    {
        // Validate the entity type and 'with' array
        $validated = SetupValidator::validate($a, $with);

        // Normalize entity type (handle aliases)
        $normalizedType = SetupValidator::normalizeType($a);

        $model = $this->dispatchSetup($normalizedType, $validated);

        $this->dispatchCatalogMutated($normalizedType, $model);

        return $model;
    }

    /**
     * Dispatch the CatalogMutated audit event (no-op if event dispatch disabled).
     */
    protected function dispatchCatalogMutated(string $entityType, Model $model): void
    {
        if (! $this->isEventDispatchEnabled()) {
            return;
        }

        $wasCreated = (bool) $model->wasRecentlyCreated;
        $actor = function_exists('auth') ? auth()->user() : null;

        event(new CatalogMutated($entityType, $model, $wasCreated, $actor));
    }

    /**
     * Delegate to the matching setup helper. Extracted from setup() so the
     * audit event fires in exactly one place after the mutation lands.
     *
     * @param  array<string, mixed>  $validated
     */
    protected function dispatchSetup(string $normalizedType, array $validated): Model
    {
        return match ($normalizedType) {
            'gamed-metric' => $this->setupGamedMetric(
                $validated['slug'] ?? null,
                $validated['name'] ?? null,
                $validated['description'] ?? null,
                $validated['icon'] ?? null,
                $validated['active'] ?? true
            ),
            'metric-level' => $this->setupMetricLevel(
                $validated['metric'] ?? null,
                $validated['level'] ?? null,
                $validated['xp'] ?? null,
                $validated['name'] ?? null,
                $validated['description'] ?? null
            ),
            'metric-level-group' => $this->setupMetricLevelGroup(
                $validated['slug'] ?? null,
                $validated['name'] ?? null,
                $validated['description'] ?? null
            ),
            'metric-level-group-level' => $this->setupMetricLevelGroupLevel(
                $validated['group'] ?? null,
                $validated['level'] ?? null,
                $validated['xp'] ?? null,
                $validated['name'] ?? null,
                $validated['description'] ?? null
            ),
            'metric-level-group-metric' => $this->setupMetricLevelGroupMetric(
                $validated['group'] ?? null,
                $validated['metric'] ?? null,
                $validated['weight'] ?? null
            ),
            'achievement' => $this->setupAchievement(
                $validated['slug'] ?? null,
                $validated['for'] ?? null,
                $validated['name'] ?? null,
                $validated['description'] ?? null,
                $validated['icon'] ?? null,
                $validated['metadata'] ?? [],
                $validated['active'] ?? true,
                $validated['order'] ?? 0,
                $validated['hidden'] ?? false
            ),
            'prize' => $this->setupPrize(
                $validated['slug'] ?? null,
                $validated['name'] ?? null,
                $validated['description'] ?? null,
                $validated['type'] ?? null,
                $validated['cost'] ?? null,
                $validated['inventory'] ?? null,
                $validated['metadata'] ?? [],
                $validated['active'] ?? true,
                $validated['order'] ?? 0
            ),
        };
    }

    /**
     * Award XP to a GamedMetric for an awardable entity.
     *
     * This is the ONLY way to give XP in the system. All XP flows through GamedMetrics.
     *
     * @param  string  $metricSlug  The GamedMetric slug to award XP to
     * @return AwardXpBuilder Fluent builder for chaining
     *
     * @example LFL::award('combat-xp')->to($user)->amount(50)->because('defeated boss')->save()
     * @example LFL::award('crafting-xp')->to($user)->amount(10)->save()
     */
    public function award(string $metricSlug): AwardXpBuilder
    {
        return new AwardXpBuilder($metricSlug, $this->gamedMetricService);
    }

    /**
     * Grant an Achievement or Prize to an awardable entity.
     *
     * @param  string  $slug  The Achievement or Prize slug
     * @return GrantBuilder Fluent builder for chaining
     *
     * @example LFL::grant('first-login')->to($user)->because('completed onboarding')->save()
     * @example LFL::grant('premium-access')->to($user)->save()
     */
    public function grant(string $slug): GrantBuilder
    {
        return new GrantBuilder($slug, $this);
    }

    /**
     * Check if a Profile has reached a specific level in a metric or metric group.
     *
     * Levels are never "granted" - they are thresholds based on accumulated XP.
     * This method checks if the profile's XP meets or exceeds the level threshold.
     *
     * @param  Model  $awardable  The awardable entity (User, Team, etc.)
     * @param  int  $level  The level number to check
     * @param  string|null  $metric  GamedMetric slug to check (mutually exclusive with $group)
     * @param  string|null  $group  MetricLevelGroup slug to check (mutually exclusive with $metric)
     * @return bool Whether the profile has reached the specified level
     *
     * @example LFL::hasLevel($user, 5, metric: 'combat-xp')
     * @example LFL::hasLevel($user, 10, group: 'overall-power')
     */
    public function hasLevel(Model $awardable, int $level, ?string $metric = null, ?string $group = null): bool
    {
        if ($metric === null && $group === null) {
            throw new InvalidArgumentException("Either 'metric' or 'group' must be specified for hasLevel()");
        }

        if ($metric !== null && $group !== null) {
            throw new InvalidArgumentException("Only one of 'metric' or 'group' can be specified for hasLevel()");
        }

        // Get the profile
        if (! method_exists($awardable, 'getProfile')) {
            throw new InvalidArgumentException('Awardable must use the Awardable trait');
        }

        $profile = $awardable->getProfile();

        if ($metric !== null) {
            return $this->metricLevelService->hasReachedLevel($profile, $metric, $level);
        }

        return $this->metricLevelGroupService->hasReachedLevel($profile, $group, $level);
    }

    /**
     * Internal: Grant an achievement to an awardable entity.
     *
     * @internal Used by GrantBuilder
     */
    public function grantAchievementInternal(
        Model $recipient,
        string $achievementSlug,
        ?string $reason = null,
        ?string $source = null,
        array $meta = [],
    ): AwardResult {
        // Validate recipient uses Awardable trait
        if (! method_exists($recipient, 'getProfile')) {
            return AwardResult::failure('Recipient must use the Awardable trait');
        }

        // Check opt-out status
        if (method_exists($recipient, 'isOptedOut') && $recipient->isOptedOut()) {
            return AwardResult::failure('Recipient has opted out of gamification');
        }

        // Find the achievement
        $achievement = Achievement::where('slug', $achievementSlug)->first();
        if (! $achievement) {
            return AwardResult::failure("Achievement '{$achievementSlug}' not found");
        }

        if (! $achievement->is_active) {
            return AwardResult::failure("Achievement '{$achievementSlug}' is not active");
        }

        // Get the profile
        $profile = $recipient->getProfile();

        // Check if already granted
        $existingGrant = AchievementGrant::where('profile_id', $profile->id)
            ->where('achievement_id', $achievement->id)
            ->first();

        if ($existingGrant) {
            return AwardResult::failure("Achievement '{$achievementSlug}' already granted");
        }

        // Create the grant
        $grant = AchievementGrant::create([
            'profile_id' => $profile->id,
            'achievement_id' => $achievement->id,
            'reason' => $reason,
            'source' => $source,
            'meta' => ! empty($meta) ? $meta : null,
            'granted_at' => now(),
        ]);

        // Update profile achievement count
        $profile->incrementAchievementCount();

        // Create result for event
        $result = AwardResult::success($grant);

        // Dispatch events
        if ($this->isEventDispatchEnabled()) {
            event(new AchievementUnlocked($recipient, $achievement, $grant, $reason, $source));
            event(new AwardGranted($result, 'achievement', $recipient, $grant));
        }

        return $result;
    }

    /**
     * Internal: Grant a prize to an awardable entity.
     *
     * @internal Used by GrantBuilder
     */
    public function grantPrizeInternal(
        Model $recipient,
        string $prizeSlug,
        ?string $reason = null,
        ?string $source = null,
        array $meta = [],
    ): AwardResult {
        // Validate recipient uses Awardable trait
        if (! method_exists($recipient, 'getProfile')) {
            return AwardResult::failure('Recipient must use the Awardable trait');
        }

        // Check opt-out status
        if (method_exists($recipient, 'isOptedOut') && $recipient->isOptedOut()) {
            return AwardResult::failure('Recipient has opted out of gamification');
        }

        // Find the prize
        $prize = Prize::findBySlug($prizeSlug);
        if (! $prize) {
            return AwardResult::failure("Prize '{$prizeSlug}' not found");
        }

        if (! $prize->is_active) {
            return AwardResult::failure("Prize '{$prizeSlug}' is not active");
        }

        if (! $prize->isAvailable()) {
            return AwardResult::failure("Prize '{$prizeSlug}' is out of inventory");
        }

        // Get the profile
        $profile = $recipient->getProfile();

        // Create the grant
        $grant = PrizeGrant::create([
            'profile_id' => $profile->id,
            'prize_id' => $prize->id,
            'reason' => $reason,
            'source' => $source,
            'meta' => ! empty($meta) ? $meta : null,
            'status' => 'granted',
            'granted_at' => now(),
        ]);

        // Update profile prize count
        $profile->incrementPrizeCount();

        // Create result for event
        $result = AwardResult::success($grant);

        // Dispatch events
        if ($this->isEventDispatchEnabled()) {
            event(new PrizeAwarded($recipient, $grant, $reason, $source, $meta));
            event(new AwardGranted($result, 'prize', $recipient, $grant));
        }

        return $result;
    }

    /**
     * Get or create a gamification profile for an awardable entity.
     *
     * @param  Model  $awardable  The entity to get the profile for
     * @return Profile|null The profile instance
     */
    public function profile(Model $awardable): ?Profile
    {
        if (! method_exists($awardable, 'getProfile')) {
            return null;
        }

        return $awardable->getProfile();
    }

    /**
     * Start building a leaderboard query with the fluent API.
     *
     * @return LeaderboardServiceContract Fluent builder for chaining
     *
     * @example LFL::leaderboard()->for(User::class)->by('xp')->take(10)
     */
    public function leaderboard(): LeaderboardServiceContract
    {
        return $this->app->make(LeaderboardServiceContract::class);
    }

    /**
     * Start building an analytics query with the fluent API.
     *
     * @return AnalyticsServiceContract Fluent builder for chaining
     *
     * @example LFL::analytics()->activeUsers()->between($start, $end)->count()
     */
    public function analytics(): AnalyticsServiceContract
    {
        return $this->app->make(AnalyticsServiceContract::class);
    }

    // =========================================================================
    // Setup Helper Methods
    // =========================================================================

    protected function setupAchievement(
        ?string $slug,
        ?string $for,
        ?string $name,
        ?string $description,
        ?string $icon,
        array $metadata,
        bool $active,
        int $order,
        bool $hidden = false
    ): Achievement {
        if ($slug === null) {
            throw new InvalidArgumentException('Achievement slug is required');
        }

        $slugValue = Str::slug($slug);
        $displayName = $name ?? Str::headline($slug);
        $awardableType = $this->normalizeAwardableType($for);

        return Achievement::updateOrCreate(
            ['slug' => $slugValue],
            [
                'name' => $displayName,
                'description' => $description,
                'icon' => $icon,
                'awardable_type' => $awardableType,
                'meta' => ! empty($metadata) ? $metadata : null,
                'is_active' => $active,
                'is_hidden' => $hidden,
                'sort_order' => $order,
            ]
        );
    }

    protected function setupGamedMetric(
        ?string $slug,
        ?string $name,
        ?string $description,
        ?string $icon,
        bool $active
    ): GamedMetric {
        if ($slug === null) {
            throw new InvalidArgumentException('GamedMetric slug is required');
        }

        $slugValue = Str::slug($slug);
        $displayName = $name ?? Str::headline($slug);

        return GamedMetric::updateOrCreate(
            ['slug' => $slugValue],
            [
                'name' => $displayName,
                'description' => $description,
                'icon' => $icon,
                'active' => $active,
            ]
        );
    }

    protected function setupMetricLevel(
        ?string $metric,
        ?int $level,
        ?int $xp,
        ?string $name,
        ?string $description
    ): MetricLevel {
        if ($metric === null) {
            throw new InvalidArgumentException("GamedMetric slug 'metric' is required for metric-level");
        }
        if ($level === null) {
            throw new InvalidArgumentException("Level number 'level' is required for metric-level");
        }
        if ($xp === null) {
            throw new InvalidArgumentException("XP threshold 'xp' is required for metric-level");
        }

        $gamedMetric = GamedMetric::findBySlug($metric);
        if (! $gamedMetric) {
            throw new InvalidArgumentException("GamedMetric '{$metric}' not found");
        }

        $displayName = $name ?? "Level {$level}";

        return MetricLevel::updateOrCreate(
            [
                'gamed_metric_id' => $gamedMetric->id,
                'level' => $level,
            ],
            [
                'xp_threshold' => $xp,
                'name' => $displayName,
                'description' => $description,
            ]
        );
    }

    protected function setupMetricLevelGroup(
        ?string $slug,
        ?string $name,
        ?string $description
    ): MetricLevelGroup {
        if ($slug === null) {
            throw new InvalidArgumentException('MetricLevelGroup slug is required');
        }

        $slugValue = Str::slug($slug);
        $displayName = $name ?? Str::headline($slug);

        return MetricLevelGroup::updateOrCreate(
            ['slug' => $slugValue],
            [
                'name' => $displayName,
                'description' => $description,
            ]
        );
    }

    protected function setupMetricLevelGroupLevel(
        ?string $group,
        ?int $level,
        ?int $xp,
        ?string $name,
        ?string $description
    ): MetricLevelGroupLevel {
        if ($group === null) {
            throw new InvalidArgumentException("MetricLevelGroup slug 'group' is required for group-level");
        }
        if ($level === null) {
            throw new InvalidArgumentException("Level number 'level' is required for group-level");
        }
        if ($xp === null) {
            throw new InvalidArgumentException("XP threshold 'xp' is required for group-level");
        }

        $metricLevelGroup = MetricLevelGroup::findBySlug($group);
        if (! $metricLevelGroup) {
            throw new InvalidArgumentException("MetricLevelGroup '{$group}' not found");
        }

        $displayName = $name ?? "Level {$level}";

        return MetricLevelGroupLevel::updateOrCreate(
            [
                'metric_level_group_id' => $metricLevelGroup->id,
                'level' => $level,
            ],
            [
                'xp_threshold' => $xp,
                'name' => $displayName,
                'description' => $description,
            ]
        );
    }

    protected function setupMetricLevelGroupMetric(
        ?string $group,
        ?string $metric,
        ?float $weight
    ): MetricLevelGroupMetric {
        if ($group === null) {
            throw new InvalidArgumentException("MetricLevelGroup slug 'group' is required");
        }
        if ($metric === null) {
            throw new InvalidArgumentException("GamedMetric slug 'metric' is required");
        }

        $metricLevelGroup = MetricLevelGroup::findBySlug($group);
        if (! $metricLevelGroup) {
            throw new InvalidArgumentException("MetricLevelGroup '{$group}' not found");
        }

        $gamedMetric = GamedMetric::findBySlug($metric);
        if (! $gamedMetric) {
            throw new InvalidArgumentException("GamedMetric '{$metric}' not found");
        }

        return MetricLevelGroupMetric::updateOrCreate(
            [
                'metric_level_group_id' => $metricLevelGroup->id,
                'gamed_metric_id' => $gamedMetric->id,
            ],
            [
                'weight' => $weight ?? 1.0,
            ]
        );
    }

    protected function setupPrize(
        ?string $slug,
        ?string $name,
        ?string $description,
        ?string $type,
        int|float|null $cost,
        ?int $inventory,
        array $metadata,
        bool $active,
        int $order
    ): Prize {
        if ($slug === null) {
            throw new InvalidArgumentException('Prize slug is required');
        }

        $slugValue = Str::slug($slug);
        $displayName = $name ?? Str::headline($slug);
        $prizeType = $type ?? 'virtual';

        return Prize::updateOrCreate(
            ['slug' => $slugValue],
            [
                'name' => $displayName,
                'description' => $description,
                'type' => $prizeType,
                'cost_in_points' => $cost ?? 0,
                'inventory_quantity' => $inventory,
                'meta' => ! empty($metadata) ? $metadata : null,
                'is_active' => $active,
                'sort_order' => $order,
            ]
        );
    }

    /**
     * Normalize an awardable type string to a fully qualified class name or null.
     */
    protected function normalizeAwardableType(?string $type): ?string
    {
        if ($type === null) {
            return null;
        }

        if (str_contains($type, '\\')) {
            return $type;
        }

        $commonNamespaces = [
            'App\\Models\\',
            'App\\',
        ];

        foreach ($commonNamespaces as $namespace) {
            $fullClass = $namespace.$type;
            if (class_exists($fullClass)) {
                return $fullClass;
            }
        }

        return $type;
    }

    // =========================================================================
    // Configuration Helper Methods
    // =========================================================================

    public function isFeatureEnabled(string $feature): bool
    {
        return (bool) config("lfl.features.{$feature}", false);
    }

    public function getEnabledFeatures(): array
    {
        $features = config('lfl.features', []);

        return array_filter($features, fn ($enabled) => $enabled === true);
    }

    public function getTablePrefix(): string
    {
        return config('lfl.table_prefix', 'lfl_');
    }

    public function getDefaultPoints(): int|float
    {
        return config('lfl.defaults.points', 10);
    }

    public function getMultiplier(string $name): float
    {
        return (float) config("lfl.defaults.multipliers.{$name}", 1.0);
    }

    public function isEventLoggingEnabled(): bool
    {
        return (bool) config('lfl.events.log_to_database', true);
    }

    public function isEventDispatchEnabled(): bool
    {
        return (bool) config('lfl.events.dispatch', true);
    }

    public function getApiPrefix(): string
    {
        return config('lfl.api.prefix', 'api/lfl');
    }

    public function isApiEnabled(): bool
    {
        return (bool) config('lfl.api.enabled', true);
    }

    public function isUiEnabled(): bool
    {
        return (bool) config('lfl.ui.enabled', false);
    }
}
