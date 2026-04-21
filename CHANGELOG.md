# Changelog

All notable changes to Laravel Fun Lab will be documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.1.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [Unreleased]

## [0.4.0] - 2026-04-21

> ⚠️  **Breaking changes — read the migration notes below before upgrading.**

### Breaking

- `config('lfl.api.writes')` default flipped from `true` to `false`. Consumers relying on write endpoints must explicitly set `LFL_API_WRITES=true` (or override the config).
- Catalog-setup POSTs (`/gamed-metrics`, `/metric-levels`, `/achievements`, `/prizes`) are now gated by a second flag, `config('lfl.api.allow_setup_over_http')`, defaulting to `false`.
- Write endpoints now require an authorization callable via `LaravelFunLab\Http\AuthorizerRegistry` (or a FQCN in `config('lfl.authorize.*')`). No callable + `allow_missing=false` → 403. Wire your policy in a service provider's `boot()`.
- `config('lfl.awardables')` must explicitly list every model class LFL is permitted to treat as an awardable over HTTP. Empty allowlist → all HTTP writes return 422.
- `LFLServiceProvider::boot()` throws `RuntimeException` when `api.writes=true` AND `api.auth.middleware=null` (previously a silent footgun). Set `LFL_API_AUTH_MIDDLEWARE=auth:sanctum` or similar.
- `Profile::$fillable` no longer includes `total_xp`, `achievement_count`, `prize_count`, `last_activity_at`. Code that does `$profile->update(['total_xp' => X])` now silently drops those fields. Use the new `Profile::setAggregates([...])` helper for internal/test/migration call-sites.
- `AwardXpBuilder::save()` now throws `LaravelFunLab\Exceptions\AwardRejectedException` (subclass of `InvalidArgumentException`) for:
  - amounts above `config('lfl.defaults.max_points_per_action')` (new default: 10000),
  - awards rejected by any registered `AwardValidationPipeline` step (XP path was bypassing the pipeline before),
  - opted-out recipients (matching existing Grant behavior).
- `AwardXpRequest` validation adds a `max` rule derived from the same cap; oversized amounts return 422 before reaching the service layer.

### Security

- **Secure-by-default writes** (C1). See Breaking section above. `LFLServiceProvider::boot()` throws when writes are enabled without auth middleware — preventing the "enabled writes but no auth" footgun.

- **Secure-by-default writes** (C1). `config('lfl.api.writes')` now defaults to `false`. `LFLServiceProvider::boot()` throws `RuntimeException` when `writes=true` and `config('lfl.api.auth.middleware')` is `null` — preventing the "enabled writes but no auth" footgun.
- **Awardable allowlist** (C3). `config('lfl.awardables')` must explicitly list the model classes LFL is permitted to treat as awardables over HTTP. All write controllers + the broadcast channel authorization reject unlisted types BEFORE `class_exists()` triggers the autoloader, closing the attacker-controlled class-name reconnaissance vector.
- **Authorization hook with deny-by-default** (C2). New `config('lfl.authorize.{award,grant,opt,setup}')` callables run before every write. When `null`, writes return 403 (override with `allow_missing=true` for dev). Each callable receives `(?Model $user, array $context)`.
- **XP cap + validation pipeline + opt-out** (H1/H2). `AwardXpBuilder::save()` now invokes `AwardValidationPipeline::validate()` (matching `GrantBuilder`) and rejects amounts above `config('lfl.defaults.max_points_per_action')` (new default: 10000). `GamedMetricService::awardXp()` gates on `Profile::isOptedOut()` to match grant behavior. Rejection surfaces as `AwardRejectedException` with a `kind` discriminator.
- **Catalog audit trail** (H3). New `CatalogMutated` event dispatched by `AwardEngine::setup()`. Logged by `EventLogSubscriber` so every upsert-by-slug has a trace (actor, entity type, was_created, timestamp).
- **Setup-over-HTTP is opt-in** (H3). `POST /gamed-metrics`, `/achievements`, `/prizes`, `/metric-levels` are now gated by `config('lfl.api.allow_setup_over_http')` (default `false`) in addition to the general `api.writes` flag.
- **Visibility settings enforced** (H4). `ProfileController::show` denies anonymous reads with 403 when `Profile::visibility_settings.public === false`. Owner can still read their own profile.
- **Mass-assignment hardening** (M1). `Profile::$fillable` no longer includes `total_xp`, `achievement_count`, `prize_count`, `last_activity_at`. Internal helpers (`incrementXp`, `touchActivity`, `recalculateAggregations`) use `forceFill` or `increment` to bypass — consumer code cannot forge XP via `Profile::update($request->all())`.
- **Rate limit on writes** (M2). New `throttle:lfl-writes` named limiter (60/min per user or IP, configurable via `LFL_API_WRITES_PER_MINUTE`) applied to every POST endpoint.
- **Broadcasting docs: payload trust model** (M3). `docs/broadcasting.md` now explains that `reason`/`source`/`meta` fields in WebSocket payloads are user-supplied and must be sanitized by consumers before rendering.

### Added

- **REST API expansion for SPA consumers**
  - Write endpoints: `POST /awards`, `POST /grants`, `POST /profiles/{type}/{id}/opt-in`, `POST /profiles/{type}/{id}/opt-out`, gated by the new `config('lfl.api.writes')` flag
  - Current-user convenience endpoints: `GET /me/profile`, `GET /me/achievements`, `GET /me/awards` (require consumer-configured auth middleware)
  - Catalog endpoints: `GET /gamed-metrics`, `GET /metric-levels/{metric}`, `GET /prizes`
  - Setup-over-HTTP endpoints for admin flows: `POST /gamed-metrics`, `POST /metric-levels`, `POST /achievements`, `POST /prizes`
  - Hand-rolled OpenAPI 3.0 spec at `resources/openapi.yaml`, served as JSON at `GET /openapi.json`, publishable via `vendor:publish --tag=lfl-openapi`
- **Opt-in broadcasting**
  - `XpAwarded`, `AchievementUnlocked`, `PrizeAwarded` now implement `ShouldBroadcast`, gated by the new `config('lfl.events.broadcast')` flag (default `false`)
  - Private channel naming: `private-lfl.profile.{AwardableType}.{id}` (backslashes in type replaced with dots)
  - Channel authorization registered automatically when broadcasting is enabled
  - `broadcastAs` event names: `xp.awarded`, `achievement.unlocked`, `prize.awarded`
  - `broadcastWith` payloads match the corresponding REST Resource shape so clients see one schema across REST and WebSockets
- **New docs**: `docs/react.md` (SPA integration), `docs/broadcasting.md` (real-time events)

### Changed

- **Eloquent API Resources refactor**
  - All API controllers now return Eloquent API Resources (`ProfileResource`, `LeaderboardEntryResource`, `AchievementResource`, `AwardResource`, `AwardGrantResource`, `GamedMetricResource`, `MetricLevelResource`, `PrizeResource`)
  - Unified `{ data, meta?, links?, profile? }` envelope across all endpoints
- **Config**
  - `config('lfl.api.writes')` added (default: `true`)
  - `config('lfl.events.broadcast')` added (default: `false`)
  - Inline comments clarify that `config('lfl.api.auth.middleware')` is the consumer's contract — LFL ships no auth implementation
- **Framework-only boundary**
  - Reinforced: LFL ships no authentication, no AI/ML, no transport driver. Consumers wrap their own security and infrastructure.

### Added (deps)

- `illuminate/http`, `illuminate/routing`, `illuminate/validation` made explicit (previously resolved via `illuminate/support`)
- `symfony/yaml` for serving the OpenAPI spec as JSON

## [0.3.1] - 2025-01-17

### Fixed

- **Test for Automatic Group Progression**
  - Fixed test that incorrectly expected manual progression check after XP awards
  - Test now correctly verifies that group progression is triggered automatically when XP is awarded

## [0.3.0] - 2025-01-16

### Fixed

- **MetricLevelGroup Logic Alignment**
  - Fixed structural misalignment between MetricLevel and MetricLevelGroup patterns
  - MetricLevelGroup now uses ProfileMetricGroup to store level progression state, matching ProfileMetric pattern
  - Group level progression is now stored persistently instead of calculated dynamically

### Added

- **ProfileMetricGroup Model**
  - New model to track level progression for MetricLevelGroups per Profile
  - Stores `current_level` for each profile/group combination
  - Mirrors ProfileMetric structure for consistency
  - Migration: `2024_01_01_000017_create_lfl_profile_metric_groups_table.php`

- **MetricLevelGroupService Enhancements**
  - `checkProgression()` now accepts ProfileMetricGroup and updates stored state
  - `getCurrentLevel()` uses stored ProfileMetricGroup.current_level with fallback
  - Added `getOrCreateProfileMetricGroup()` helper method
  - Automatic group progression checking when XP is awarded to metrics in groups

- **GamedMetricService Integration**
  - Automatically checks group progression after awarding XP to any metric
  - Finds all groups containing the metric and updates their progression

- **Admin UI Updates**
  - MetricLevelGroups view now shows ProfileMetricGroup statistics
  - Displays count of profiles tracking each group

- **Tests**
  - Comprehensive test suite for ProfileMetricGroup model
  - Tests for stored level progression and automatic group checking
  - 5 new test cases in MetricLevelGroupTest.php

### Changed

- **MetricLevelGroupLevel Model**
  - Added `scopeForGroup()` method to match MetricLevel::scopeForMetric() pattern

- **MetricLevelGroup Model**
  - Added `profileMetricGroups()` relationship

### Added

- **Installation Workflow** (Story #6)
  - Created `lfl:install` artisan command for streamlined package installation
  - Interactive table prefix configuration with Laravel Prompts
  - Config publishing with automatic prefix customization
  - Migration running with user confirmation prompt
  - `--ui` flag for optional UI component scaffolding
  - `--force` flag to overwrite existing configuration files
  - `--skip-migrations` flag to skip database migrations
  - `--prefix` option for non-interactive table prefix setting
  - Beautiful welcome banner and success message with quick start guide
  - Non-interactive mode detection for CI/CD environments and testing
  - 13 feature tests covering all command functionality

- **Config System** (Story #5)
  - Enhanced `config/lfl.php` with default point values configuration
  - Added `defaults.points` for global default point amount (default: 10)
  - Added `defaults.multipliers` for streak_bonus (1.5x) and first_time_bonus (2.0x)
  - Added `default_amount` to each award type in `award_types` config
  - Implemented feature flag helper methods via LFL facade:
    - `LFL::isFeatureEnabled($feature)` - Check if specific feature is enabled
    - `LFL::getEnabledFeatures()` - Get all enabled features
    - `LFL::getTablePrefix()` - Get configured table prefix
    - `LFL::getDefaultPoints()` - Get default points amount
    - `LFL::getMultiplier($name)` - Get multiplier value from config
    - `LFL::isEventLoggingEnabled()` - Check event logging status
    - `LFL::isEventDispatchEnabled()` - Check event dispatch status
    - `LFL::isApiEnabled()` / `LFL::getApiPrefix()` - API configuration helpers
    - `LFL::isUiEnabled()` - UI layer status check
  - AwardBuilder now uses config defaults when no amount is explicitly specified
  - 20 comprehensive unit tests for configuration system
  - All acceptance criteria met: publishable config, table prefix, feature flags, default values, event toggles, API prefix

- **Event Pipeline** (Story #4)
  - Created type-specific events: `PointsAwarded`, `AchievementUnlocked`, `PrizeAwarded`, `BadgeAwarded`
  - All events implement `LflEvent` contract with consistent interface
  - Events contain full context: recipient, award/grant, amount, reason, source, metadata
  - `PointsAwarded` includes `previousTotal` and `newTotal` for tracking
  - `AchievementUnlocked` includes achievement model and grant record
  - Created `EventLog` model for analytics and auditing
  - EventLog captures: event_type, award_type, awardable, amount, reason, source, context JSON
  - Strategic indexes on event_type, award_type, source, occurred_at for query performance
  - Scopes: `ofEventType()`, `ofAwardType()`, `forAwardable()`, `fromSource()`, `recent()`, `between()`
  - `EventLogSubscriber` auto-logs events when `lfl.events.log_to_database` is enabled
  - Both generic (`AwardGranted`) and specific events dispatched for flexibility
  - 25 tests covering all event types and EventLog functionality

- **Dynamic Achievement Setup** (Story #3)
  - Implemented `LFL::setup()` method for runtime achievement definition
  - Named parameters API: `an` (slug), `for` (awardable type), `name`, `description`, `icon`, `metadata`, `active`, `order`
  - Upsert logic: creates new achievements or updates existing ones by slug
  - Automatic slug generation from achievement name with `Str::slug()`
  - Human-readable name generation from slug with `Str::headline()`
  - Awardable type normalization: short class names (e.g., 'User') resolved to FQCN when class exists
  - Flexible JSON metadata storage for custom attributes
  - 29 feature tests covering creation, upsert, metadata, type handling, and edge cases

- **Award Engine Workflow** (Story #2)
  - Implemented unified award API: `LFL::award(type)->to($recipient)->for('reason')->from('source')->amount(n)->grant()`
  - Created `AwardBuilder` fluent builder for expressive award operations
  - Created `AwardResult` value object encapsulating success/failure status, errors, and metadata
  - Created `AwardType` enum with `Points`, `Achievement`, `Prize`, `Badge` types
  - Shorthand methods: `awardPoints()`, `grantAchievement()`, `awardPrize()`, `awardBadge()`
  - Event dispatching: `AwardGranted` and `AwardFailed` events with configurable toggle
  - Points accumulation with previous/new total tracking in result meta
  - Achievement validation: active status, duplicate prevention, slug resolution
  - Recipient validation: ensures model uses `Awardable` trait
  - 28 feature tests covering all award operations, validation, and events

- **Core Models & Awardables** (Story #1)
  - Created `Awardable` trait for Eloquent models with relationships and helper methods
  - Created `Award` model for point grants with polymorphic relationships
  - Created `Achievement` model for achievement definitions with metadata
  - Created `AchievementGrant` model for tracking awarded achievements
  - Created 3 migrations with configurable table prefix support (`lfl_awards`, `lfl_achievements`, `lfl_achievement_grants`)
  - Added scopes: `ofType()`, `forAwardableType()`, `fromSource()`, `active()`, `ordered()`
  - Helper methods: `getTotalPoints()`, `hasAchievement()`, `getAchievements()`, `getAwardCount()`, `getRecentAwards()`, `getRecentAchievements()`
  - 35 unit tests covering all models and trait functionality

- **Package Development Setup** (Story #15)
  - Created package directory structure at `packages/laravel-fun-lab/`
  - Set up PSR-4 autoloading for `LaravelFunLab\` namespace
  - Created `LFLServiceProvider` with publishable config, migrations, routes, and views
  - Created `LFL` facade resolving to `AwardEngine` service
  - Created `config/lfl.php` with table_prefix, feature flags, API/UI configuration
  - Created `AwardEngine` service with placeholder methods: `award()`, `setup()`, `profile()`, `leaderboard()`
  - Created `routes/api.php` stub for future API routes

