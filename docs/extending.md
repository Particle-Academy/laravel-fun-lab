# Extension Guide

This guide covers extending Laravel Fun Lab with custom implementations, award types, validation, and more.

## Table of Contents

- [Custom XP Currencies](#custom-xp-currencies)
- [Service Swapping](#service-swapping)
- [Macros](#macros)
- [Validation Pipelines](#validation-pipelines)
- [Event Listeners](#event-listeners)
- [Custom Models](#custom-models)

## Custom XP Currencies

There is one way to give XP: `LFL::award($metricSlug)`, and every kind of XP is a
**gamed metric**. A new currency ("coins", "stars", "reputation") is a new gamed
metric, with levels if it should have them:

```php
use LaravelFunLab\Facades\LFL;

LFL::setup(a: 'gamed-metric', with: ['slug' => 'coins', 'name' => 'Coins', 'icon' => 'coin']);
LFL::setup(a: 'metric-level', with: ['metric' => 'coins', 'level' => 2, 'xp' => 1000, 'name' => 'Saver']);

LFL::award('coins')->to($user)->amount(50)->because('purchase')->save();
```

To weigh several currencies together (an "overall power" score), put them in a
metric level group with a weight per metric:

```php
LFL::setup(a: 'metric-level-group', with: ['slug' => 'overall']);
LFL::setup(a: 'metric-level-group-metric', with: ['group' => 'overall', 'metric' => 'combat-xp', 'weight' => 1.0]);
LFL::setup(a: 'metric-level-group-metric', with: ['group' => 'overall', 'metric' => 'coins', 'weight' => 0.1]);
```

`AwardTypeRegistry` and `config('lfl.award_types')` still exist and hold display
metadata (name, icon) for the award types the event log records. Registering a
type there does **not** make `LFL::award()` accept it: `award()` takes a gamed
metric slug and nothing else. (Before 0.8.0 the docs said otherwise; that path
belonged to an `AwardBuilder` nothing could reach, which 0.8.0 removed.)

## Service Swapping

LFL uses contracts for all major services, allowing you to swap implementations.

### Available Contracts

- `AwardEngineContract` - Award engine service
- `LeaderboardServiceContract` - Leaderboard builder
- `AnalyticsServiceContract` - Analytics builder

### Creating Custom Implementations

#### Custom Award Engine

```php
namespace App\Services;

use LaravelFunLab\Services\AwardEngine;
use LaravelFunLab\Services\AwardXpBuilder;

class CustomAwardEngine extends AwardEngine
{
    public function award(string $metricSlug): AwardXpBuilder
    {
        // Custom behaviour, then the stock builder.
        return parent::award($metricSlug);
    }
}
```

Implementing `AwardEngineContract` from scratch means implementing `setup()`,
`award(string $metricSlug): AwardXpBuilder`, `grant(string $slug): GrantBuilder`,
`hasLevel()`, `profile()`, `leaderboard()`, `analytics()` and the configuration
readers; extending `AwardEngine` is usually what you want.


#### Custom Leaderboard Service

```php
namespace App\Services;

use LaravelFunLab\Contracts\LeaderboardServiceContract;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Collection;

class CustomLeaderboardService implements LeaderboardServiceContract
{
    public function for(string $type): self
    {
        // Custom implementation
        return $this;
    }
    
    // Implement other required methods...
}
```

### Binding Custom Services

Bind your custom implementations in `AppServiceProvider`:

```php
use LaravelFunLab\Contracts\AwardEngineContract;
use App\Services\CustomAwardEngine;

public function register(): void
{
    $this->app->singleton(AwardEngineContract::class, CustomAwardEngine::class);
}
```

Or configure via `config/lfl.php`:

```php
'services' => [
    'award_engine' => \App\Services\CustomAwardEngine::class,
    'leaderboard' => \App\Services\CustomLeaderboardService::class,
    'analytics' => \App\Services\CustomAnalyticsService::class,
],
```

## Macros

Add custom methods to the LFL facade using macros.

### Registering Macros

Register macros in your `AppServiceProvider`:

```php
use LaravelFunLab\Services\AwardEngine;

public function boot(): void
{
    // Simple macro
    AwardEngine::macro('awardBonusPoints', function ($user, $multiplier = 1.5) {
        $basePoints = config('lfl.defaults.points', 10);
        $bonusPoints = $basePoints * $multiplier;
        
        return LFL::awardPoints($user, $bonusPoints, 'bonus', 'system');
    });
    
    // Macro with instance context
    AwardEngine::macro('getConfigValue', function ($key) {
        return config("lfl.{$key}");
    });
}
```

### Using Macros

```php
use LaravelFunLab\Facades\LFL;

// Call macro via facade
$result = LFL::awardBonusPoints($user, 2.0);

$value = LFL::getConfigValue('defaults.points');
```

### Checking for Macros

```php
use LaravelFunLab\Services\AwardEngine;

if (AwardEngine::hasMacro('awardBonusPoints')) {
    // Macro exists
}
```

## Validation Pipelines

Add custom validation logic to award operations using the validation pipeline.

### Adding Validation Steps

Register validation steps in your `AppServiceProvider`:

```php
use LaravelFunLab\Pipelines\AwardValidationPipeline;
use LaravelFunLab\Enums\AwardType;

public function boot(): void
{
    // Maximum points per award
    AwardValidationPipeline::addStep(function ($awardable, $type, $amount) {
        if ($type === AwardType::Points && $amount > 1000) {
            return [
                'valid' => false,
                'message' => 'Maximum points per award is 1000',
                'errors' => ['amount' => ['Amount cannot exceed 1000']],
            ];
        }
        
        return ['valid' => true];
    });
    
    // Rate limiting
    AwardValidationPipeline::addStep(function ($awardable, $type, $amount, $reason, $source) {
        $recentAwards = $awardable->awards()
            ->where('source', $source)
            ->where('created_at', '>=', now()->subHour())
            ->count();
        
        if ($recentAwards >= 10) {
            return [
                'valid' => false,
                'message' => 'Too many awards from this source',
                'errors' => ['rate_limit' => ['Maximum 10 awards per hour from this source']],
            ];
        }
        
        return ['valid' => true];
    });
    
    // User-specific validation
    AwardValidationPipeline::addStep(function ($awardable, $type, $amount) {
        // Example: Premium users can receive more points
        if ($type === AwardType::Points && $awardable->isPremium()) {
            // Premium users have no limit
            return ['valid' => true];
        }
        
        // Regular users limited to 500 points per award
        if ($type === AwardType::Points && $amount > 500) {
            return [
                'valid' => false,
                'message' => 'Regular users limited to 500 points per award',
                'errors' => ['amount' => ['Upgrade to premium for higher limits']],
            ];
        }
        
        return ['valid' => true];
    });
}
```

### Validation Step Parameters

Each validation step receives:

- `$awardable` - The recipient model (with `Awardable` trait)
- `$type` - Award type (`AwardType` enum or string)
- `$amount` - Award amount (int|float)
- `$reason` - Reason for award (string|null)
- `$source` - Source identifier (string|null)
- `$meta` - Additional metadata (array)

### Validation Return Values

Return `['valid' => true]` to pass validation, or:

```php
return [
    'valid' => false,
    'message' => 'Human-readable error message',
    'errors' => [
        'field' => ['Error message for field'],
    ],
];
```

### Pipeline Behavior

- Steps execute in registration order
- First failure halts the pipeline
- All steps must pass for award to succeed
- Failed validation prevents award from being granted

## Event Listeners

Listen to LFL events to add custom behavior.

### Available Events

- `PointsAwarded` - When points are awarded
- `AchievementUnlocked` - When achievement is unlocked
- `PrizeAwarded` - When prize is awarded
- `BadgeAwarded` - When badge is awarded
- `AwardGranted` - Generic award event
- `AwardFailed` - When award operation fails

### Creating Event Listeners

```php
namespace App\Listeners;

use LaravelFunLab\Events\PointsAwarded;
use LaravelFunLab\Events\AchievementUnlocked;

class AwardNotificationListener
{
    public function handle(PointsAwarded $event): void
    {
        // Send notification
        $event->recipient->notify(
            new PointsAwardedNotification($event->amount, $event->reason)
        );
        
        // Log to external analytics
        Analytics::track('points_awarded', [
            'user_id' => $event->recipient->id,
            'amount' => $event->amount,
            'total' => $event->newTotal,
        ]);
    }
    
    public function handleAchievement(AchievementUnlocked $event): void
    {
        // Send achievement notification
        $event->recipient->notify(
            new AchievementUnlockedNotification($event->achievement)
        );
        
        // Grant bonus points for achievement
        if ($event->achievement->slug === 'first-login') {
            LFL::awardPoints($event->recipient, 50, 'first login bonus', 'system');
        }
    }
}
```

### Registering Event Listeners

In `AppServiceProvider`:

```php
use LaravelFunLab\Events\PointsAwarded;
use LaravelFunLab\Events\AchievementUnlocked;
use App\Listeners\AwardNotificationListener;

public function boot(): void
{
    Event::listen(PointsAwarded::class, AwardNotificationListener::class);
    Event::listen(AchievementUnlocked::class, [AwardNotificationListener::class, 'handleAchievement']);
}
```

Or use auto-discovery in `EventServiceProvider`:

```php
protected $listen = [
    PointsAwarded::class => [
        AwardNotificationListener::class,
    ],
    AchievementUnlocked::class => [
        AwardNotificationListener::class,
    ],
];
```

## Custom Models

LFL resolves its own models directly, so a subclass cannot be swapped in for
one. To add behaviour, observe the models it writes. The ones worth observing:

| Model | Written when |
|---|---|
| `ProfileMetric` | XP is awarded to a gamed metric (`total_xp`, `current_level`) |
| `AchievementGrant` | an achievement is granted |
| `PrizeGrant` | a prize is granted, claimed or fulfilled |
| `EventLog` | any logged event: XP, achievement, prize, catalog change |

```php
namespace App\Observers;

use LaravelFunLab\Models\PrizeGrant;

class PrizeGrantObserver
{
    public function created(PrizeGrant $grant): void
    {
        // e.g. queue fulfilment for a physical prize
    }
}
```

Register it in `AppServiceProvider`:

```php
use App\Observers\PrizeGrantObserver;
use LaravelFunLab\Models\PrizeGrant;

public function boot(): void
{
    PrizeGrant::observe(PrizeGrantObserver::class);
}
```

For most reactions the events (`XpAwarded`, `MetricLevelReached`,
`AchievementUnlocked`, `PrizeAwarded`) are simpler than observers, and they
carry the recipient.

## Advanced Examples

### Reacting to a Level-Up

Levels are thresholds on a gamed metric, and crossing one dispatches
`MetricLevelReached` once per level crossed, in ascending order:

```php
use Illuminate\Support\Facades\Event;
use LaravelFunLab\Events\MetricLevelReached;
use LaravelFunLab\Facades\LFL;

Event::listen(MetricLevelReached::class, function (MetricLevelReached $event) {
    if ($event->level->level === 5) {
        LFL::grant('veteran')->to($event->recipient)->because('Reached level 5')->save();
    }
});
```

To grant an achievement on a level without code, attach it to the level instead;
progression grants it automatically.


### Custom Analytics Service

```php
namespace App\Services;

use LaravelFunLab\Contracts\AnalyticsServiceContract;
use LaravelFunLab\Builders\AnalyticsBuilder;

class CustomAnalyticsService extends AnalyticsBuilder
{
    public function total(): float
    {
        $total = parent::total();
        
        // Add custom caching
        return cache()->remember(
            "analytics.total.{$this->getCacheKey()}",
            now()->addHour(),
            fn() => $total
        );
    }
    
    protected function getCacheKey(): string
    {
        return md5(serialize([
            $this->awardType,
            $this->awardableType,
            $this->startDate,
            $this->endDate,
        ]));
    }
}
```

## Next Steps

- [Usage Guide](usage.md) - Practical examples and patterns
- [API Reference](api.md) - Complete API documentation
- [Configuration Reference](configuration.md) - All configuration options

