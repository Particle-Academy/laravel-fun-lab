# API Reference

Complete API documentation for Laravel Fun Lab.

## Table of Contents

- [LFL Facade](#lfl-facade)
- [AwardBuilder](#awardbuilder)
- [AnalyticsBuilder](#analyticsbuilder)
- [LeaderboardBuilder](#leaderboardbuilder)
- [Awardable Trait](#awardable-trait)
- [Models](#models)
- [Events](#events)
- [Value Objects](#value-objects)
- [Enums](#enums)

## LFL Facade

The `LFL` facade provides static access to all Laravel Fun Lab functionality.

```php
use LaravelFunLab\Facades\LFL;
```

### Award Methods

#### `award(AwardType|string $type): AwardBuilder`

Start building an award operation with the fluent API.

```php
LFL::award('achievement')->to($user)->achievement('first-login')->grant();
LFL::award('prize')->to($user)->withMeta(['prize_slug' => 'premium'])->grant();
```

**Parameters:**
- `$type` - Award type: `'achievement'`, `'prize'`, or a GamedMetric slug

**Returns:** `AwardBuilder` instance

#### `awardGamedMetric(Model $recipient, string|GamedMetric $gamedMetric, int $amount): ProfileMetric`

Award XP to a specific GamedMetric.

```php
$profileMetric = LFL::awardGamedMetric($user, 'combat-xp', 50);
echo $profileMetric->total_xp; // 50
```

**Parameters:**
- `$recipient` - Model instance with `Awardable` trait
- `$gamedMetric` - GamedMetric slug or model instance
- `$amount` - XP amount to award

**Returns:** `ProfileMetric` - The updated profile metric record

**Throws:** `InvalidArgumentException` if GamedMetric not found or inactive

#### `grantAchievement(Model $recipient, string $achievementSlug, ?string $reason = null, ?string $source = null, array $meta = []): AwardResult`

Quick method to grant an achievement.

```php
LFL::grantAchievement($user, 'first-login', 'completed first login', 'auth');
```

**Parameters:**
- `$recipient` - Model instance with `Awardable` trait
- `$achievementSlug` - Achievement slug identifier
- `$reason` - Reason for the grant
- `$source` - Source identifier
- `$meta` - Additional metadata array

**Returns:** `AwardResult`

#### `awardPrize(Model $recipient, ?string $reason = null, ?string $source = null, array $meta = []): AwardResult`

Quick method to award a prize.

```php
LFL::awardPrize($user, 'won contest', 'contest-system');
```

**Returns:** `AwardResult`

#### `awardBadge(Model $recipient, ?string $reason = null, ?string $source = null, array $meta = []): AwardResult`

Quick method to award a badge.

```php
LFL::awardBadge($user, 'early adopter', 'admin');
```

**Returns:** `AwardResult`

### GamedMetric Methods

#### `awardGamedMetric(Model $recipient, string|GamedMetric $gamedMetric, int $amount): ProfileMetric`

Award XP to a specific GamedMetric (XP bucket). Automatically checks level progression for the metric and grants achievements attached to reached levels. Also automatically checks progression for any MetricLevelGroups that contain this metric.

```php
// Award combat XP by slug
$profileMetric = LFL::awardGamedMetric($user, 'combat-xp', 100);

// Award using GamedMetric model
$combatMetric = GamedMetric::findBySlug('combat-xp');
$profileMetric = LFL::awardGamedMetric($user, $combatMetric, 50);
```

**Parameters:**
- `$recipient` - Model instance with `Awardable` trait
- `$gamedMetric` - GamedMetric slug string or model instance
- `$amount` - XP amount to award

**Returns:** `ProfileMetric` - The updated profile metric record

**Note:** This method automatically:
1. Updates the ProfileMetric with new XP total
2. Checks individual metric level progression
3. Checks group progression for all MetricLevelGroups containing this metric
4. Grants achievements attached to reached levels (both metric and group levels)

### Achievement Setup

#### `setup(string $an, ?string $for = null, ?string $name = null, ?string $description = null, ?string $icon = null, array $metadata = [], bool $active = true, int $order = 0): Achievement`

Set up a new achievement dynamically.

```php
LFL::setup(
    an: 'first-login',
    for: 'User',
    name: 'First Login',
    description: 'Welcome!',
    icon: 'star'
);
```

**Parameters:**
- `$an` - Achievement slug (required)
- `$for` - Awardable type (e.g., `'User'`)
- `$name` - Display name (auto-generated from slug if not provided)
- `$description` - Achievement description
- `$icon` - Icon identifier
- `$metadata` - Custom metadata array
- `$active` - Whether achievement is active
- `$order` - Display order

**Returns:** `Achievement` model

### Profile Methods

#### `profile(mixed $awardable): Profile`

Get or create a gamification profile.

```php
$profile = LFL::profile($user);
```

**Returns:** `Profile` model

### Leaderboard Methods

#### `leaderboard(): LeaderboardServiceContract`

Start building a leaderboard query.

```php
LFL::leaderboard()->for(User::class)->by('points')->get();
```

**Returns:** `LeaderboardBuilder` instance

### Analytics Methods

#### `analytics(): AnalyticsServiceContract`

Start building an analytics query.

```php
LFL::analytics()->byType('points')->period('monthly')->total();
```

**Returns:** `AnalyticsBuilder` instance

### Configuration Methods

#### `isFeatureEnabled(string $feature): bool`

Check if a specific feature is enabled.

```php
LFL::isFeatureEnabled('achievements'); // true/false
```

#### `getEnabledFeatures(): array`

Get all enabled features.

```php
$features = LFL::getEnabledFeatures(); // ['achievements', 'leaderboards', ...]
```

#### `getTablePrefix(): string`

Get the configured table prefix.

```php
$prefix = LFL::getTablePrefix(); // 'lfl_'
```

#### `getDefaultPoints(): int|float`

Get the default points amount.

```php
$default = LFL::getDefaultPoints(); // 10
```

#### `getMultiplier(string $name): float`

Get a multiplier value from config.

```php
$streakBonus = LFL::getMultiplier('streak_bonus'); // 1.5
```

#### `isEventLoggingEnabled(): bool`

Check if event logging is enabled.

#### `isEventDispatchEnabled(): bool`

Check if event dispatching is enabled.

#### `getApiPrefix(): string`

Get the configured API prefix.

#### `isApiEnabled(): bool`

Check if the API layer is enabled.

#### `isUiEnabled(): bool`

Check if the UI layer is enabled.

## AwardBuilder

Fluent builder for award operations.

### Methods

#### `to(Model $recipient): self`

Set the recipient of the award.

```php
LFL::award('points')->to($user);
```

#### `for(string $reason): self`

Set the reason for the award.

```php
LFL::award('points')->for('task completion');
```

#### `from(string $source): self`

Set the source/origin of the award.

```php
LFL::award('points')->from('task-system');
```

#### `amount(int|float $amount): self`

Set the amount for cumulative awards.

```php
LFL::award('points')->amount(50);
```

#### `withMeta(array $meta): self`

Add metadata to the award.

```php
LFL::award('points')->withMeta(['task_id' => 123]);
```

#### `achievement(string|Achievement $achievement): self`

Specify which achievement to grant (for achievement type awards).

```php
LFL::award('achievement')->achievement('first-login');
```

#### `grant(): AwardResult`

Execute the award operation and persist to database.

```php
$result = LFL::award('points')->to($user)->amount(50)->grant();
```

**Returns:** `AwardResult`

## AnalyticsBuilder

Fluent builder for analytics queries.

### Filtering Methods

#### `byType(AwardType|string $type): self`

Filter analytics by award type.

```php
LFL::analytics()->byType('points');
```

#### `forAwardableType(string $type): self`

Filter analytics by awardable type.

```php
LFL::analytics()->forAwardableType(User::class);
```

#### `forAwardable(Model $awardable): self`

Filter analytics by specific awardable entity.

```php
LFL::analytics()->forAwardable($user);
```

#### `fromSource(string $source): self`

Filter analytics by source.

```php
LFL::analytics()->fromSource('task-system');
```

#### `forAchievement(string $slug): self`

Filter analytics by achievement slug.

```php
LFL::analytics()->forAchievement('first-login');
```

### Time Period Methods

#### `period(?string $period = null): self`

Filter analytics by time period.

```php
LFL::analytics()->period('weekly'); // 'daily', 'weekly', 'monthly', 'yearly'
```

#### `between(Carbon|string $start, Carbon|string|null $end = null): self`

Filter analytics between two dates.

```php
LFL::analytics()->between($startDate, $endDate);
```

#### `since(Carbon|string $date): self`

Filter analytics since a specific date.

```php
LFL::analytics()->since(Carbon::now()->subDays(7));
```

#### `until(Carbon|string $date): self`

Filter analytics until a specific date.

```php
LFL::analytics()->until($endDate);
```

### Aggregation Methods

#### `count(): int`

Get the total count of events.

```php
$count = LFL::analytics()->byType('points')->count();
```

#### `total(): float`

Get the total amount (sum) for cumulative award types.

```php
$total = LFL::analytics()->byType('points')->total();
```

#### `average(): float`

Get the average amount.

```php
$avg = LFL::analytics()->byType('points')->average();
```

#### `min(): ?float`

Get the minimum amount.

```php
$min = LFL::analytics()->byType('points')->min();
```

#### `max(): ?float`

Get the maximum amount.

```php
$max = LFL::analytics()->byType('points')->max();
```

#### `activeUsers(): int`

Get distinct active users within the filtered period.

```php
$active = LFL::analytics()->since(Carbon::now()->subDays(7))->activeUsers();
```

#### `achievementCompletionRate(?string $achievementSlug = null): float`

Get achievement completion rate as percentage (0-100).

```php
$rate = LFL::analytics()->forAchievement('first-login')->achievementCompletionRate();
```

### Grouping Methods

#### `timeSeries(string $interval = 'day'): array`

Get time-series data grouped by interval.

```php
$data = LFL::analytics()->timeSeries('day'); // 'hour', 'day', 'week', 'month', 'year'
```

**Returns:** Array of periods with `period`, `count`, and `total` keys.

#### `byAwardType(): array`

Get aggregated data grouped by award type.

```php
$byType = LFL::analytics()->byAwardType();
```

#### `byAwardableType(): array`

Get aggregated data grouped by awardable type.

```php
$byType = LFL::analytics()->byAwardableType();
```

#### `bySource(): array`

Get aggregated data grouped by source.

```php
$bySource = LFL::analytics()->bySource();
```

### Export Methods

#### `export(?int $limit = null): array`

Get export-ready data as an array.

```php
$events = LFL::analytics()->export(limit: 1000);
```

#### `query(): Builder`

Get the underlying query builder for custom queries.

```php
$query = LFL::analytics()->query()->where('amount', '>', 100);
```

## LeaderboardBuilder

Fluent builder for leaderboard queries.

### Methods

#### `for(string $type): self`

Filter leaderboard by awardable type.

```php
LFL::leaderboard()->for(User::class);
```

#### `by(string $metric): self`

Sort leaderboard by metric.

```php
LFL::leaderboard()->by('points'); // 'points', 'achievements', 'prizes'
```

#### `period(?string $period = null): self`

Filter leaderboard by time period.

```php
LFL::leaderboard()->period('weekly'); // 'daily', 'weekly', 'monthly', 'all-time'
```

#### `limit(int $limit): self`

Set the maximum number of results.

```php
LFL::leaderboard()->limit(10);
```

#### `perPage(int $perPage): self`

Set pagination per page.

```php
LFL::leaderboard()->perPage(15);
```

#### `page(int $page): self`

Set the current page number.

```php
LFL::leaderboard()->page(2);
```

#### `includeOptedOut(bool $include = true): self`

Include opted-out users in leaderboard.

```php
LFL::leaderboard()->includeOptedOut();
```

#### `get(): Collection`

Get leaderboard results as a collection.

```php
$leaderboard = LFL::leaderboard()->for(User::class)->by('points')->get();
```

#### `paginate(?int $perPage = null): LengthAwarePaginator`

Get paginated leaderboard results.

```php
$leaderboard = LFL::leaderboard()->for(User::class)->by('points')->paginate(15);
```

## Awardable Trait

Trait to add to Eloquent models for gamification features.

### Relationships

#### `awards(): MorphMany`

Get all awards (points/badges) granted to this model.

```php
$user->awards;
```

#### `achievementGrants(): MorphMany`

Get all achievement grants for this model.

```php
$user->achievementGrants;
```

### Helper Methods

#### `getTotalPoints(?string $type = 'points'): int|float`

Get total points accumulated by this model.

```php
$total = $user->getTotalPoints();
```

#### `hasAchievement(string|Achievement $achievement): bool`

Check if this model has a specific achievement.

```php
if ($user->hasAchievement('first-login')) {
    // ...
}
```

#### `getAchievements(): Collection`

Get all achievements unlocked by this model.

```php
$achievements = $user->getAchievements();
```

#### `getAwardCount(string $type): int`

Get award count by type.

```php
$count = $user->getAwardCount('points');
```

#### `getRecentAwards(int $limit = 10): Collection`

Get recent awards.

```php
$recent = $user->getRecentAwards(10);
```

#### `getRecentAchievements(int $limit = 10): Collection`

Get recent achievements.

```php
$recent = $user->getRecentAchievements(10);
```

## Models

### Award

Represents a point or badge award.

**Properties:**
- `id` - Award ID
- `type` - Award type (`'points'`, `'badge'`, etc.)
- `amount` - Award amount
- `reason` - Reason for award
- `source` - Source identifier
- `meta` - Metadata JSON
- `awardable_type` - Recipient model type
- `awardable_id` - Recipient model ID
- `created_at` - Creation timestamp

### Achievement

Represents an achievement definition.

**Properties:**
- `id` - Achievement ID
- `slug` - Unique slug identifier
- `name` - Display name
- `description` - Achievement description
- `icon` - Icon identifier
- `awardable_type` - Target awardable type
- `metadata` - Custom metadata JSON
- `is_active` - Whether achievement is active
- `order` - Display order

### AchievementGrant

Represents a granted achievement to a user.

**Properties:**
- `id` - Grant ID
- `achievement_id` - Achievement ID
- `awardable_type` - Recipient model type
- `awardable_id` - Recipient model ID
- `reason` - Reason for grant
- `source` - Source identifier
- `meta` - Metadata JSON
- `granted_at` - Grant timestamp

### Profile

Represents a gamification profile for an awardable.

**Properties:**
- `id` - Profile ID
- `awardable_type` - Model type
- `awardable_id` - Model ID
- `total_points` - Total points accumulated
- `achievement_count` - Number of achievements
- `prize_count` - Number of prizes
- `opt_out` - Whether user opted out
- `updated_at` - Last update timestamp

### EventLog

Represents an event log entry for analytics.

**Properties:**
- `id` - Log ID
- `event_type` - Event type (`'points_awarded'`, `'achievement_unlocked'`, etc.)
- `award_type` - Award type
- `awardable_type` - Recipient model type
- `awardable_id` - Recipient model ID
- `achievement_slug` - Achievement slug (if applicable)
- `amount` - Award amount
- `reason` - Reason
- `source` - Source identifier
- `context` - Context JSON
- `occurred_at` - Event timestamp

### GamedMetric

Represents an independent XP category (XP bucket) for tracking accumulated experience separately.

**Properties:**
- `id` - GamedMetric ID
- `name` - Display name (e.g., "Combat XP")
- `slug` - Unique slug identifier (e.g., "combat-xp")
- `description` - Optional description
- `icon` - Icon identifier
- `active` - Whether the metric is active
- `created_at` - Creation timestamp
- `updated_at` - Last update timestamp

**Methods:**
- `findBySlug(string $slug): ?GamedMetric` - Find a GamedMetric by slug

### MetricLevel

Represents a level threshold for a GamedMetric. When a user reaches the XP threshold, they progress to this level.

**Properties:**
- `id` - MetricLevel ID
- `gamed_metric_id` - Parent GamedMetric ID
- `level` - Level number (1, 2, 3, etc.)
- `xp_threshold` - XP required to reach this level
- `name` - Level name (e.g., "Novice Fighter")
- `description` - Optional description
- `created_at` - Creation timestamp
- `updated_at` - Last update timestamp

**Relationships:**
- `gamedMetric()` - BelongsTo GamedMetric
- `achievements()` - BelongsToMany Achievement (achievements auto-granted when level is reached)

**Methods:**
- `isReached(int $xp): bool` - Check if the given XP reaches this level threshold

### MetricLevelGroup

Combines multiple GamedMetrics to create composite leveling systems (e.g., "Total Player Level" combining Combat + Magic + Crafting XP).

**Properties:**
- `id` - MetricLevelGroup ID
- `name` - Display name
- `slug` - Unique slug identifier
- `description` - Optional description
- `created_at` - Creation timestamp
- `updated_at` - Last update timestamp

**Relationships:**
- `metrics()` - HasMany MetricLevelGroupMetric (the GamedMetrics in this group with weights)
- `levels()` - HasMany MetricLevelGroupLevel (level thresholds for combined XP)

**Methods:**
- `findBySlug(string $slug): ?MetricLevelGroup` - Find a group by slug

### MetricLevelGroupMetric

Pivot model linking a GamedMetric to a MetricLevelGroup with a weight multiplier.

**Properties:**
- `id` - ID
- `metric_level_group_id` - Parent group ID
- `gamed_metric_id` - GamedMetric ID
- `weight` - Weight multiplier (e.g., 1.0 for full weight, 0.5 for half)

### MetricLevelGroupLevel

Represents a level threshold for a MetricLevelGroup based on combined weighted XP.

**Properties:**
- `id` - MetricLevelGroupLevel ID
- `metric_level_group_id` - Parent group ID
- `level` - Level number
- `xp_threshold` - Combined XP required to reach this level
- `name` - Level name
- `description` - Optional description

**Relationships:**
- `achievements()` - BelongsToMany Achievement (achievements auto-granted when group level is reached)

### ProfileMetricGroup

Tracks level progression for a MetricLevelGroup per Profile. Stores the current level persistently to avoid recalculating on every query.

**Properties:**
- `id` - ProfileMetricGroup ID
- `profile_id` - Profile ID
- `metric_level_group_id` - MetricLevelGroup ID
- `current_level` - Current level number (stored, not calculated)
- `created_at` - Creation timestamp
- `updated_at` - Last update timestamp

**Relationships:**
- `profile()` - BelongsTo Profile
- `metricLevelGroup()` - BelongsTo MetricLevelGroup

**Methods:**
- `setLevel(int $level): void` - Update the current level

**Note:** ProfileMetricGroup records are automatically created and updated when XP is awarded to metrics that belong to a group. Group progression is checked automatically after each XP award.

### ProfileMetric

Tracks accumulated XP and current level for a specific GamedMetric per Profile.

**Properties:**
- `id` - ProfileMetric ID
- `profile_id` - Profile ID
- `gamed_metric_id` - GamedMetric ID
- `total_xp` - Total accumulated XP for this metric
- `current_level` - Current level number (stored, not calculated)
- `created_at` - Creation timestamp
- `updated_at` - Last update timestamp

**Relationships:**
- `profile()` - BelongsTo Profile
- `gamedMetric()` - BelongsTo GamedMetric

**Methods:**
- `setLevel(int $level): void` - Update the current level

**Note:** ProfileMetric records are automatically created when XP is awarded to a GamedMetric. Level progression is checked and updated automatically after each XP award.

## Services

### MetricLevelService

Service for managing level progression within individual GamedMetrics.

```php
use LaravelFunLab\Services\MetricLevelService;

$levelService = app(MetricLevelService::class);
```

#### `getCurrentLevel(Model $awardable, string|GamedMetric $gamedMetric): int`

Get the current level for a user in a specific GamedMetric.

```php
$level = $levelService->getCurrentLevel($user, 'combat-xp');
// Returns: 3
```

#### `getNextLevelThreshold(Model $awardable, string|GamedMetric $gamedMetric): ?int`

Get the XP threshold for the next level.

```php
$threshold = $levelService->getNextLevelThreshold($user, 'combat-xp');
// Returns: 500 (or null if max level reached)
```

#### `getProgressPercentage(Model $awardable, string|GamedMetric $gamedMetric): float`

Get progress percentage towards the next level (0-100).

```php
$progress = $levelService->getProgressPercentage($user, 'combat-xp');
// Returns: 65.5
```

#### `checkProgression(ProfileMetric $profileMetric): array`

Check and update level progression after XP is awarded. Returns information about levels unlocked. This method is called automatically when XP is awarded, but can also be called manually.

```php
$profileMetric = ProfileMetric::where('profile_id', $profile->id)
    ->where('gamed_metric_id', $metric->id)
    ->first();

$result = $levelService->checkProgression($profileMetric);
// Returns: [
//     'level_reached' => true,
//     'new_level' => 4,
//     'levels_unlocked' => [MetricLevel, MetricLevel]
// ]
```

**Note:** Level progression is automatically checked when XP is awarded via `LFL::award()` or `LFL::awardGamedMetric()`. You typically don't need to call this method manually.

### MetricLevelGroupService

Service for managing composite level progression across multiple GamedMetrics.

```php
use LaravelFunLab\Services\MetricLevelGroupService;

$groupService = app(MetricLevelGroupService::class);
```

#### `getTotalXp(Model $awardable, string|MetricLevelGroup $group): int`

Get total combined weighted XP from all GamedMetrics in the group.

```php
$totalXp = $groupService->getTotalXp($user, 'total-player-level');
// Returns: 1500
```

#### `getCurrentLevel(Model $awardable, string|MetricLevelGroup $group): int`

Get current level based on combined XP.

```php
$level = $groupService->getCurrentLevel($user, 'total-player-level');
// Returns: 5
```

#### `getNextLevelThreshold(Model $awardable, string|MetricLevelGroup $group): ?int`

Get the XP threshold for the next group level.

```php
$threshold = $groupService->getNextLevelThreshold($user, 'total-player-level');
// Returns: 2000
```

#### `getProgressPercentage(Model $awardable, string|MetricLevelGroup $group): float`

Get progress percentage towards the next group level.

```php
$progress = $groupService->getProgressPercentage($user, 'total-player-level');
// Returns: 75.0
```

#### `getLevelInfo(Model $awardable, string|MetricLevelGroup $group): array`

Get comprehensive level information in one call.

```php
$info = $groupService->getLevelInfo($user, 'total-player-level');
// Returns: [
//     'current_level' => 5,
//     'total_xp' => 1500,
//     'next_level_threshold' => 2000,
//     'progress_percentage' => 75.0
// ]
```

#### `getOrCreateProfileMetricGroup(Profile $profile, MetricLevelGroup $group): ProfileMetricGroup`

Get or create a ProfileMetricGroup for a profile and group combination.

```php
$profileMetricGroup = $groupService->getOrCreateProfileMetricGroup($profile, $group);
// Returns: ProfileMetricGroup with current_level = 1 (if new)
```

#### `checkProgression(ProfileMetricGroup $profileMetricGroup): array`

Check and update group level progression. This method is called automatically when XP is awarded to metrics in a group, but can also be called manually.

```php
$profileMetricGroup = $groupService->getOrCreateProfileMetricGroup($profile, $group);
$result = $groupService->checkProgression($profileMetricGroup);
// Returns: [
//     'level_reached' => true,
//     'new_level' => 5,
//     'levels_unlocked' => [MetricLevelGroupLevel, ...]
// ]
```

**Note:** Group progression is automatically checked when XP is awarded to any GamedMetric that belongs to a MetricLevelGroup. You typically don't need to call this method manually unless you're doing bulk XP updates or need to re-check progression after manual data changes.

## Events

### PointsAwarded

Dispatched when points are awarded.

**Properties:**
- `$recipient` - Award recipient
- `$award` - Award model
- `$amount` - Points amount
- `$reason` - Reason
- `$source` - Source
- `$previousTotal` - Previous total points
- `$newTotal` - New total points
- `$meta` - Metadata

### AchievementUnlocked

Dispatched when an achievement is unlocked.

**Properties:**
- `$recipient` - Award recipient
- `$achievement` - Achievement model
- `$grant` - AchievementGrant model
- `$reason` - Reason
- `$source` - Source
- `$meta` - Metadata

### PrizeAwarded

Dispatched when a prize is awarded.

### BadgeAwarded

Dispatched when a badge is awarded.

### AwardGranted

Generic event dispatched for any award.

### AwardFailed

Dispatched when an award operation fails.

## Value Objects

### AwardResult

Result of an award operation.

**Properties:**
- `$success` - Whether operation succeeded
- `$type` - Award type
- `$award` - Award model (if successful)
- `$message` - Success/error message
- `$errors` - Validation errors (if failed)
- `$meta` - Additional metadata

**Methods:**
- `successful(): bool` - Check if successful
- `failed(): bool` - Check if failed

## Enums

### AwardType

Award type enumeration.

**Values:**
- `Points` - Points award
- `Achievement` - Achievement grant
- `Prize` - Prize award
- `Badge` - Badge award

```php
use LaravelFunLab\Enums\AwardType;

AwardType::Points;
AwardType::Achievement;
```

## REST API Endpoints

When the API layer is enabled (`config('lfl.api.enabled') => true`), LFL exposes REST endpoints for accessing and mutating gamification state.

### Base URL

All endpoints are prefixed with the configured API prefix (default: `api/lfl`). A machine-readable OpenAPI 3.0 spec is available at `/api/lfl/openapi.json` and committed at `packages/laravel-fun-lab/resources/openapi.yaml`.

### Authentication contract

LFL ships **no authentication**. Point `config('lfl.api.auth.middleware')` at any Laravel middleware that resolves `auth()->user()` to an Awardable model (`auth:sanctum`, `auth:api`, a custom guard, etc.). Leave it `null` for a fully public API. The `/me/*` endpoints require this middleware to be set; all other endpoints honor whatever middleware stack you configure.

```php
'api' => [
    'enabled' => true,
    'writes' => true,
    'auth' => [
        'middleware' => 'auth:sanctum',
    ],
],
```

### Write gate

All mutation endpoints (POST) are collectively gated by `config('lfl.api.writes')` (default: `true`). Set to `false` for read-only API deployments — the routes are not registered and return 404.

### Spec

#### `GET /api/lfl/openapi.json`

Returns the OpenAPI 3.0 spec as JSON. Useful for generating typed clients.

### Current user (requires auth)

The authenticated model must use the `Awardable` trait; otherwise a 422 is returned.

#### `GET /api/lfl/me/profile`

**Response:**
```json
{
  "data": {
    "id": 1,
    "awardable_type": "App\\Models\\User",
    "awardable_id": 1,
    "is_opted_in": true,
    "display_preferences": null,
    "visibility_settings": null,
    "total_xp": 1250,
    "achievement_count": 5,
    "prize_count": 2,
    "last_activity_at": "2024-12-30T12:00:00Z",
    "created_at": "2024-01-01T00:00:00Z",
    "updated_at": "2024-12-30T12:00:00Z"
  }
}
```

#### `GET /api/lfl/me/achievements`

Returns achievements granted to the authenticated user. Response shape matches `GET /achievements`.

#### `GET /api/lfl/me/awards`

Returns paginated `ProfileMetric` records for the authenticated user. Response shape matches `GET /awards/{type}/{id}`.

### Profiles

#### `GET /api/lfl/profiles/{type}/{id}`

Get profile data for a specific awardable entity.

**Parameters:**
- `type` - Fully qualified class name (e.g., `App\Models\User`)
- `id` - Awardable ID

**Response:** See `/me/profile` (same shape).

#### `POST /api/lfl/profiles/{type}/{id}/opt-in`

Opt the awardable back into gamification. Returns the updated profile resource.

#### `POST /api/lfl/profiles/{type}/{id}/opt-out`

Opt the awardable out of gamification. Returns the updated profile resource.

### Awards (XP)

#### `GET /api/lfl/awards/{type}/{id}`

Paginated XP records (ProfileMetrics) for a specific awardable.

**Query Parameters:**
- `metric_slug` - Filter by GamedMetric slug
- `per_page` (default: 15)
- `page` (default: 1)

**Response:**
```json
{
  "data": [
    {
      "id": 1,
      "profile_id": 1,
      "gamed_metric_id": 1,
      "gamed_metric_slug": "combat-xp",
      "gamed_metric_name": "Combat XP",
      "total_xp": 500,
      "current_level": 3,
      "created_at": "2024-12-30T12:00:00Z",
      "updated_at": "2024-12-30T12:00:00Z"
    }
  ],
  "profile": { "id": 1, "total_xp": 500, "achievement_count": 5, "prize_count": 2, "is_opted_in": true },
  "meta": { "current_page": 1, "per_page": 15, "total": 1 },
  "links": { "first": "...", "last": "...", "prev": null, "next": null }
}
```

#### `POST /api/lfl/awards`

Award XP to an awardable entity. Wraps `LFL::award($metric)->to()->amount()->because()->from()->withMeta()->save()`.

**Body:**
```json
{
  "metric_slug": "combat-xp",
  "awardable_type": "App\\Models\\User",
  "awardable_id": 1,
  "amount": 50,
  "reason": "defeated boss",
  "source": "combat-system",
  "meta": {}
}
```

**Response:** The updated ProfileMetric as an Award resource.

### Grants (Achievements + Prizes)

#### `POST /api/lfl/grants`

Grant an Achievement or Prize. The slug auto-resolves to the correct entity.

**Body:**
```json
{
  "slug": "first-login",
  "awardable_type": "App\\Models\\User",
  "awardable_id": 1,
  "reason": "completed onboarding",
  "source": "registration-flow"
}
```

**Response:**
```json
{
  "data": {
    "grant_type": "achievement",
    "id": 1,
    "profile_id": 1,
    "achievement_id": 1,
    "achievement_slug": "first-login",
    "achievement_name": "First Login",
    "reason": "completed onboarding",
    "source": "registration-flow",
    "meta": null,
    "granted_at": "2024-12-30T12:00:00Z"
  }
}
```

For prizes the `grant_type` is `"prize"` and fields include `prize_id`, `prize_slug`, `prize_name`, `status`.

### Leaderboards

#### `GET /api/lfl/leaderboards/{type}`

**Query Parameters:**
- `by` - `xp`, `achievements`, `prizes` (default: `xp`)
- `period` - `daily`, `weekly`, `monthly`, `all-time` (default: `all-time`)
- `per_page` (default: 15)
- `page` (default: 1)

**Response:**
```json
{
  "data": [
    {
      "rank": 1,
      "id": 1,
      "awardable_type": "App\\Models\\User",
      "awardable_id": 1,
      "total_xp": 5000,
      "achievement_count": 10,
      "prize_count": 3,
      "last_activity_at": "2024-12-30T12:00:00Z"
    }
  ],
  "meta": { "current_page": 1, "per_page": 15, "total": 100 },
  "links": { "first": "...", "last": "...", "prev": null, "next": "..." }
}
```

### Catalog

#### `GET /api/lfl/achievements`

List all available achievements.

**Query Parameters:**
- `awardable_type` - Filter by targeted type
- `active` (default: true)

#### `POST /api/lfl/achievements`

Create an achievement. Wraps `LFL::setup('achievement', ...)`.

**Body:**
```json
{
  "slug": "first-login",
  "name": "First Login",
  "description": "Welcome!",
  "icon": "star",
  "for": "App\\Models\\User",
  "active": true
}
```

#### `GET /api/lfl/gamed-metrics`

List GamedMetrics. Supports `?active=true|false`.

#### `POST /api/lfl/gamed-metrics`

Create a GamedMetric. Wraps `LFL::setup('gamed-metric', ...)`.

#### `GET /api/lfl/metric-levels/{metric}`

List MetricLevels for a given GamedMetric slug.

#### `POST /api/lfl/metric-levels`

Create a MetricLevel threshold. Wraps `LFL::setup('metric-level', ...)`.

**Body:**
```json
{
  "metric": "combat-xp",
  "level": 3,
  "xp": 500,
  "name": "Warrior"
}
```

#### `GET /api/lfl/prizes`

List active prizes. Supports `?active=false`.

#### `POST /api/lfl/prizes`

Create a Prize. Wraps `LFL::setup('prize', ...)`.

### Error shapes

Validation errors return HTTP 422:
```json
{
  "message": "The amount field must be an integer.",
  "errors": { "amount": ["The amount field must be an integer."] }
}
```

Not-found and misconfiguration errors return HTTP 404 or 422 with:
```json
{ "message": "Awardable not found for App\\Models\\User#99." }
```

### Related

- [React / SPA integration](react.md)
- [Broadcasting](broadcasting.md)

## Next Steps

- [Usage Guide](usage.md) - Practical examples and patterns
- [Configuration Reference](configuration.md) - All configuration options
- [Extension Guide](extending.md) - Custom implementations
- [React / SPA integration](react.md) - Consuming the API from a frontend
- [Broadcasting](broadcasting.md) - Real-time event streaming

