# API Reference

Complete API documentation for Laravel Fun Lab.

## Table of Contents

- [LFL Facade](#lfl-facade)
- [AwardXpBuilder](#awardxpbuilder)
- [GrantBuilder](#grantbuilder)
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

### Setup

#### `setup(string $a, array $with = []): Model`

Create or update a catalog entity, matched on its slug (or on metric + level for
levels). `$a` is one of `gamed-metric`, `metric-level`, `metric-level-group`,
`metric-level-group-level`, `metric-level-group-metric`, `achievement`, `prize`.

```php
LFL::setup(a: 'gamed-metric', with: ['slug' => 'combat-xp', 'name' => 'Combat XP']);
LFL::setup(a: 'metric-level', with: ['metric' => 'combat-xp', 'level' => 2, 'xp' => 100, 'name' => 'Apprentice']);
LFL::setup(a: 'achievement', with: ['slug' => 'first-login', 'description' => 'Welcome!', 'icon' => 'star']);
LFL::setup(a: 'prize', with: ['slug' => 'premium-access', 'type' => 'virtual', 'inventory' => 100]);
```

A missing `name` defaults to the headline form of the slug (`first-login` is
"First Login"; a level is "Level N").

**Returns:** the created or updated model.

### Awarding XP

#### `award(string $metricSlug): AwardXpBuilder`

All XP flows through a gamed metric. See [AwardXpBuilder](#awardxpbuilder).

```php
$profileMetric = LFL::award('combat-xp')->to($user)->amount(50)->because('defeated boss')->save();
```

Saving an award also checks level progression for that metric and for every
metric level group containing it, and grants the active achievements attached
to each level reached.

### Granting achievements and prizes

#### `grant(string $slug): GrantBuilder`

Grant an achievement or a prize by slug. See [GrantBuilder](#grantbuilder).

```php
$result = LFL::grant('first-login')->to($user)->because('completed onboarding')->save();
```

**Returns:** a `GrantBuilder`; its `save()` returns an `AwardResult`.

### Levels

#### `hasLevel(Model $awardable, int $level, ?string $metric = null, ?string $group = null): bool`

Whether the awardable has reached `$level` in a metric or a metric level group.
Pass exactly one of `metric` and `group`.

```php
LFL::hasLevel($user, 5, metric: 'combat-xp');
LFL::hasLevel($user, 10, group: 'overall-power');
```

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

## AwardXpBuilder

Returned by `LFL::award($metricSlug)`.

### Methods

#### `to(Model $recipient): self`

The awardable receiving the XP (uses the `Awardable` trait). Required.

#### `amount(int $amount): self`

The XP to add. Must be greater than 0 and no more than
`lfl.defaults.max_points_per_action` (10000 by default; 0 turns the cap off).

#### `because(string $reason): self` / `for(string $reason): self`

Why the XP was awarded.

#### `from(string $source): self`

Where the award came from.

#### `withMeta(array $meta): self`

Extra data carried on the `XpAwarded` event and the event log.

#### `save(): ProfileMetric` / `grant(): ProfileMetric`

Award the XP and return the updated `ProfileMetric`.

**Throws:** `InvalidArgumentException` when the recipient or amount is missing or
the metric is unknown or inactive; `AwardRejectedException` with reason
`cap_exceeded`, `pipeline_rejected` or `opted_out`.

## GrantBuilder

Returned by `LFL::grant($slug)`. The slug is resolved to an achievement first,
then a prize.

### Methods

#### `to(Model $recipient): self`

The awardable receiving the grant. Required.

#### `because(string $reason): self` / `for(string $reason): self`

Why it was granted.

#### `from(string $source): self`

Where the grant came from.

#### `withMeta(array $meta): self`

Extra data stored on the grant.

#### `save(): AwardResult` / `grant(): AwardResult`

Grant it. An achievement is granted once per profile; a second grant fails. A
prize can be granted repeatedly until its inventory runs out.

**Returns:** `AwardResult`. A failure carries a message; it does not throw.

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

Fluent builder for leaderboard queries. Ties are broken on profile id, so paging
never repeats or skips an entry. Opted-out profiles are excluded unless you ask.

### Methods

#### `for(string $type): self`

Only rank awardables of this type.

```php
LFL::leaderboard()->for(User::class);
```

#### `by(string $sort): self`

Rank on `'xp'` (the default; `'points'` is an alias), `'achievements'` or `'prizes'`.

#### `period(?string $period = null): self`

`'daily'`, `'weekly'` (from Monday), `'monthly'` or `'all-time'` (the default).
A period board ranks on the XP **earned within the period**, read from the event
log, and lists only profiles that earned some. Each entry carries it as `period_xp`.

```php
LFL::leaderboard()->period('weekly')->take(10);
```

#### `metric(string|GamedMetric|null $metric): self`

Rank on one gamed metric's XP instead of the profile-wide total, listing only
profiles with a record for it. Entries carry `metric_xp` and `metric_level`.
Combined with `period()`, the period score is that metric's XP earned in the
period. An unknown slug throws `InvalidArgumentException`.

#### `excludeOptedOut(bool $exclude = true): self`

Pass `false` to include opted-out profiles.

#### `perPage(int $perPage): self` / `page(int $page): self`

Pagination for `paginate()`.

#### `take(int $limit): Collection`

The top `$limit` entries, ranked from 1.

#### `get(): Collection`

Every entry, ranked from 1.

#### `paginate(): LengthAwarePaginator`

One page, ranked from the page's offset.

```php
$page = LFL::leaderboard()->for(User::class)->by('xp')->perPage(15)->page(2)->paginate();
```

## Awardable Trait

Add to any Eloquent model that can earn XP, achievements and prizes. It brings
`HasProfile` with it.

### Profile

- `profile(): MorphOne` / `getProfile(): Profile` (created on first use) / `hasProfile(): bool`
- `isOptedIn(): bool` / `isOptedOut(): bool` / `optIn(): bool` / `optOut(): bool`

### XP and levels

- `getTotalXp(): int` - XP across every gamed metric
- `getXpFor(string|GamedMetric $metric): int`
- `getLevelFor(string|GamedMetric $metric): int` - 1 until a level is reached

### Achievements and prizes

- `hasAchievement(string|Achievement $achievement): bool`
- `getAchievements(): Collection`
- `getAchievementCount(): int` / `getPrizeCount(): int`
- `getRecentAchievements(int $limit = 10): Collection`
- `getRecentPrizes(int $limit = 10): Collection`

```php
if ($user->hasAchievement('first-login')) {
    // ...
}
$combatLevel = $user->getLevelFor('combat-xp');
```

## Models

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

**Note:** Level progression is automatically checked when XP is awarded via `LFL::award()`. You typically don't need to call this method manually.

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
- `metric` - a GamedMetric slug. Ranks on that metric's XP and lists only profiles with XP in it. Unknown slug: `422`.
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

With `?metric=combat-xp`, each entry also carries
`"metric": { "slug": "combat-xp", "total_xp": 1200, "current_level": 4 }`.
On a `daily`, `weekly` or `monthly` board, each entry carries `period_xp`, the
score it is ranked on: the XP earned since the period started, from the event
log. With the event log off (`lfl.events.log_to_database` or
`lfl.events.dispatch` false) a period board is a `422` on `period`.

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

