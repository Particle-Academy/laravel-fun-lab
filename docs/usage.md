# Usage Guide

This guide provides practical examples and patterns for using Laravel Fun Lab in your application.

## Table of Contents

- [Setting Up Models](#setting-up-models)
- [Profiles](#profiles)
- [GamedMetrics (XP System)](#gamedmetrics-xp-system)
- [MetricLevelGroups (Composite Leveling)](#metriclevelgroups-composite-leveling)
- [Achievements](#achievements)
- [Prizes](#prizes)
- [Leaderboards](#leaderboards)
- [Analytics](#analytics)
- [Common Patterns](#common-patterns)

## Setting Up Models

### Add the Awardable Trait

Add the `Awardable` trait to any Eloquent model you want to gamify:

```php
use LaravelFunLab\Traits\Awardable;
use Illuminate\Foundation\Auth\User as Authenticatable;

class User extends Authenticatable
{
    use Awardable;
    
    // ... your existing code
}
```

The trait works with any model - Users, Teams, Organizations, etc.:

```php
class Team extends Model
{
    use Awardable;
}

class Organization extends Model
{
    use Awardable;
}
```

### What the Awardable Trait Provides

The trait provides:
- **Profile relationship**: Each awardable model gets a Profile that tracks XP, achievements, and prizes
- **Achievement grants relationship**: `achievementGrants()`
- **Prize grants relationship**: `prizeGrants()`
- **Helper methods**: `hasAchievement()`, `getProfile()`, `isOptedIn()`, etc.

### Using Helper Methods

```php
$user = User::find(1);

// Get or create the user's profile
$profile = $user->getProfile();
echo $profile->total_xp;
echo $profile->achievement_count;
echo $profile->prize_count;

// Check if user has an achievement
if ($user->hasAchievement('first-login')) {
    // ...
}

// Check opt-in status
if ($user->isOptedIn()) {
    // User participates in gamification
}
```

## Profiles

Every awardable model has a Profile that tracks:
- **Total XP**: Sum of all XP across all GamedMetrics
- **Achievement count**: Number of achievements earned
- **Prize count**: Number of prizes awarded
- **Opt-in status**: Whether the user participates in gamification
- **Display preferences**: User preferences for how their profile is shown
- **Visibility settings**: What parts of their profile are public

### Getting a Profile

```php
// Get or create profile
$profile = $user->getProfile();

// Check if profile exists
if ($user->hasProfile()) {
    // ...
}

// Access profile data
echo $profile->total_xp;
echo $profile->achievement_count;
echo $profile->prize_count;
echo $profile->last_activity_at;
```

### Opt-In/Opt-Out

Users can opt out of gamification:

```php
// Opt out
$user->optOut();

// Opt back in
$user->optIn();

// Check status
if ($user->isOptedIn()) {
    // User participates
}

if ($user->isOptedOut()) {
    // User has opted out - they won't appear on leaderboards
}
```

## GamedMetrics (XP System)

GamedMetrics are independent XP categories. Each metric tracks its own XP and can have its own level progression.

### Creating GamedMetrics

```php
use LaravelFunLab\Facades\LFL;

// Create via setup()
LFL::setup(a: 'gamed-metric', with: [
    'slug' => 'combat-xp',
    'name' => 'Combat XP',
    'description' => 'Experience from combat activities',
]);

LFL::setup(a: 'gamed-metric', with: [
    'slug' => 'crafting-xp',
    'name' => 'Crafting XP',
    'description' => 'Experience from crafting items',
    'active' => true,
]);
```

### Awarding XP

```php
use LaravelFunLab\Facades\LFL;

// Award XP to a specific GamedMetric
$profileMetric = LFL::award('combat-xp')
    ->to($user)
    ->amount(50)
    ->save();

// Check the result
echo $profileMetric->total_xp; // XP for this specific metric
echo $profileMetric->current_level; // Current level in this metric

// Total XP is automatically updated on the profile
echo $user->getProfile()->fresh()->total_xp;
```

### Querying XP

```php
$profile = $user->getProfile();

// Get XP for a specific metric
$combatXp = $profile->getXpFor('combat-xp');

// Get level for a specific metric
$combatLevel = $profile->getLevelFor('combat-xp');

// Get total XP across all metrics
$totalXp = $profile->total_xp;

// Get all profile metrics
$metrics = $profile->metrics; // Collection of ProfileMetric
```

### Level Progression

Define levels for GamedMetrics using LFL::setup():

```php
use LaravelFunLab\Facades\LFL;

// Create levels for a GamedMetric
LFL::setup(a: 'metric-level', with: [
    'metric' => 'combat-xp',
    'level' => 1,
    'xp' => 0,
    'name' => 'Novice',
]);

LFL::setup(a: 'metric-level', with: [
    'metric' => 'combat-xp',
    'level' => 2,
    'xp' => 100,
    'name' => 'Apprentice',
]);

LFL::setup(a: 'metric-level', with: [
    'metric' => 'combat-xp',
    'level' => 3,
    'xp' => 500,
    'name' => 'Journeyman',
]);
```

Level progression happens automatically when XP is awarded.

## MetricLevelGroups (Composite Leveling)

MetricLevelGroups allow you to combine multiple GamedMetrics with weights to create composite leveling systems. For example, a "Total Player Level" that combines Combat XP, Magic XP, and Crafting XP.

### Creating MetricLevelGroups

```php
use LaravelFunLab\Facades\LFL;

// Create a group
LFL::setup(a: 'metric-level-group', with: [
    'slug' => 'total-player-level',
    'name' => 'Total Player Level',
    'description' => 'Combined level from all activities',
]);

// Add metrics to the group with weights
LFL::setup(
    a: 'metric-level-group-metric',
    with: [
        'group' => 'total-player-level',
        'metric' => 'combat-xp',
        'weight' => 1.0, // Full weight
    ]
);

LFL::setup(
    a: 'metric-level-group-metric',
    with: [
        'group' => 'total-player-level',
        'metric' => 'magic-xp',
        'weight' => 0.8, // 80% weight
    ]
);

LFL::setup(
    a: 'metric-level-group-metric',
    with: [
        'group' => 'total-player-level',
        'metric' => 'crafting-xp',
        'weight' => 0.5, // 50% weight
    ]
);
```

### Creating Group Levels

```php
// Create level thresholds for the group
LFL::setup(
    a: 'metric-level-group-level',
    with: [
        'group' => 'total-player-level',
        'level' => 1,
        'xp' => 0,
        'name' => 'Novice',
    ]
);

LFL::setup(
    a: 'metric-level-group-level',
    with: [
        'group' => 'total-player-level',
        'level' => 2,
        'xp' => 500,
        'name' => 'Apprentice',
    ]
);

LFL::setup(
    a: 'metric-level-group-level',
    with: [
        'group' => 'total-player-level',
        'level' => 3,
        'xp' => 1500,
        'name' => 'Journeyman',
    ]
);
```

### Automatic Group Progression

**Group progression is checked automatically** when XP is awarded to any metric in the group. You don't need to manually trigger progression checks.

```php
// Award XP to combat - group progression is automatically checked
LFL::award('combat-xp')->to($user)->amount(100)->save();

// Award XP to magic - group progression is automatically checked again
LFL::award('magic-xp')->to($user)->amount(50)->save();

// The ProfileMetricGroup is automatically created and updated
// with the current level based on combined weighted XP
```

### Querying Group Levels

```php
use LaravelFunLab\Services\MetricLevelGroupService;

$groupService = app(MetricLevelGroupService::class);

// Get current level in a group
$level = $groupService->getCurrentLevel($user, 'total-player-level');
// Returns: 3

// Get total combined XP (weighted)
$totalXp = $groupService->getTotalXp($user, 'total-player-level');
// Returns: 1200 (combat: 500*1.0 + magic: 400*0.8 + crafting: 200*0.5)

// Get progress percentage to next level
$progress = $groupService->getProgressPercentage($user, 'total-player-level');
// Returns: 70.0 (70% to next level)

// Get comprehensive level info
$info = $groupService->getLevelInfo($user, 'total-player-level');
// Returns: [
//     'current_level' => 3,
//     'total_xp' => 1200,
//     'next_level_threshold' => 1500,
//     'progress_percentage' => 70.0
// ]
```

### Accessing ProfileMetricGroup

The ProfileMetricGroup model stores the current level persistently:

```php
use LaravelFunLab\Models\ProfileMetricGroup;
use LaravelFunLab\Models\MetricLevelGroup;

$profile = $user->getProfile();
$group = MetricLevelGroup::findBySlug('total-player-level');

$profileMetricGroup = ProfileMetricGroup::where('profile_id', $profile->id)
    ->where('metric_level_group_id', $group->id)
    ->first();

if ($profileMetricGroup) {
    echo $profileMetricGroup->current_level; // Stored level
}
```

### Auto-Awarding Achievements on Group Level-Up

You can attach achievements to group levels that are automatically granted when the level is reached:

```php
use LaravelFunLab\Models\MetricLevelGroupLevel;
use LaravelFunLab\Models\Achievement;

$level = MetricLevelGroupLevel::where('metric_level_group_id', $group->id)
    ->where('level', 5)
    ->first();

$achievement = Achievement::where('slug', 'level-5-master')->first();

// Attach achievement to level
$level->achievements()->attach($achievement->id);

// When user reaches level 5 in the group, the achievement is automatically granted
```

## Achievements

### Creating Achievements

```php
use LaravelFunLab\Facades\LFL;

// Via setup()
LFL::setup(a: 'achievement', with: [
    'slug' => 'first-login',
    'for' => 'User', // Optional: restrict to specific model type
    'name' => 'First Login',
    'description' => 'Welcome! You\'ve logged in for the first time.',
    'icon' => 'star',
]);

LFL::setup(a: 'achievement', with: [
    'slug' => 'task-master',
    'name' => 'Task Master',
    'description' => 'Complete 100 tasks',
    'icon' => 'trophy',
    'active' => true,
]);
```

### Granting Achievements

```php
use LaravelFunLab\Facades\LFL;

// Grant an achievement
$result = LFL::grant('first-login')
    ->to($user)
    ->because('completed first login')
    ->from('auth')
    ->save();

if ($result->succeeded()) {
    echo "Achievement unlocked!";
}

// Check if already granted
if ($user->hasAchievement('first-login')) {
    // Already has this achievement
}
```

### Fluent API for Achievements

```php
$result = LFL::grant('first-login')
    ->to($user)
    ->because('completed first login')
    ->from('auth-system')
    ->save();
```

## Prizes

### Creating Prizes

```php
use LaravelFunLab\Facades\LFL;

LFL::setup(a: 'prize', with: [
    'slug' => 'premium-month',
    'name' => '1 Month Premium Access',
    'description' => 'One month of premium features',
    'type' => 'virtual',
    'metadata' => ['duration_days' => 30],
]);

LFL::setup(a: 'prize', with: [
    'slug' => 'tshirt',
    'name' => 'LFL T-Shirt',
    'description' => 'Exclusive branded t-shirt',
    'type' => 'physical',
]);
```

### Awarding Prizes

```php
use LaravelFunLab\Facades\LFL;

$result = LFL::grant('premium-month')
    ->to($user)
    ->because('won monthly contest')
    ->from('contest-system')
    ->save();

if ($result->succeeded()) {
    echo "Prize awarded!";
}
```

## Leaderboards

### Basic Leaderboard

```php
use LaravelFunLab\Facades\LFL;

// Get top 10 users by XP
$leaderboard = LFL::leaderboard()
    ->for(User::class)
    ->by('xp')
    ->take(10);

foreach ($leaderboard as $profile) {
    echo "{$profile->rank}. {$profile->awardable->name}: {$profile->total_xp} XP";
}
```

### Sorting Options

```php
// By XP (default)
->by('xp')

// By achievements
->by('achievements')

// By prizes
->by('prizes')
```

### Per-Metric Leaderboards

A GamedMetric is an XP type. `metric()` ranks on one metric's XP instead of the
profile-wide total, and lists only profiles with a record for it:

```php
$combat = LFL::leaderboard()
    ->for(User::class)
    ->metric('combat-xp')      // slug or GamedMetric model
    ->period('weekly')         // optional; the period score is this metric's XP alone
    ->perPage(20)
    ->paginate();

foreach ($combat as $profile) {
    echo "{$profile->rank}. {$profile->metric_xp} XP, level {$profile->metric_level}";
}
```

With `by('achievements')` or `by('prizes')`, the metric's participants are sorted
on their profile-wide counts. An unknown slug throws `InvalidArgumentException`.
Opted-out profiles are excluded as on every board, and ties break on profile id,
so paging never repeats or skips a row.

### Time-Based Leaderboards

```php
// Daily leaderboard
$daily = LFL::leaderboard()
    ->for(User::class)
    ->period('daily')
    ->take(10);

// Weekly leaderboard
$weekly = LFL::leaderboard()
    ->for(User::class)
    ->period('weekly')
    ->take(10);

// Monthly leaderboard
$monthly = LFL::leaderboard()
    ->for(User::class)
    ->period('monthly')
    ->take(10);

// All-time
$allTime = LFL::leaderboard()
    ->for(User::class)
    ->period('all-time')
    ->take(10);
```

### Pagination

```php
$paginator = LFL::leaderboard()
    ->for(User::class)
    ->perPage(20)
    ->page(1)
    ->paginate();
```

### Include Opted-Out Users

By default, opted-out users are excluded:

```php
// Include everyone
$leaderboard = LFL::leaderboard()
    ->for(User::class)
    ->excludeOptedOut(false)
    ->get();
```

## Analytics

### Profile Statistics

```php
use LaravelFunLab\Models\Profile;

// Count profiles with XP
$activeProfiles = Profile::where('total_xp', '>', 0)->count();

// Average XP
$avgXp = Profile::avg('total_xp');

// Top earners
$topEarners = Profile::orderByDesc('total_xp')
    ->take(10)
    ->get();
```

### Achievement Completion Rate

```php
$totalProfiles = Profile::count();
$profilesWithAchievement = Profile::where('achievement_count', '>', 0)->count();
$completionRate = ($profilesWithAchievement / $totalProfiles) * 100;
```

## Common Patterns

### Award XP on Task Completion

```php
class TaskController extends Controller
{
    public function complete(Task $task)
    {
        $task->markAsComplete();
        
        // Award XP based on task difficulty
        $xp = match($task->difficulty) {
            'easy' => 10,
            'medium' => 25,
            'hard' => 50,
            default => 10,
        };
        
        LFL::award('task-xp')
            ->to(auth()->user())
            ->amount($xp)
            ->save();
        
        return back()->with('success', "Task completed! +{$xp} XP");
    }
}
```

### Grant Achievement on First Action

```php
class LoginController extends Controller
{
    public function login(Request $request)
    {
        // ... authentication logic
        
        $user = auth()->user();
        
        // Grant first-login achievement if not already granted
        if (!$user->hasAchievement('first-login')) {
            LFL::grant('first-login')
                ->to($user)
                ->because('First successful login')
                ->from('auth')
                ->save();
        }
        
        return redirect()->intended();
    }
}
```

### Daily Login Bonus

```php
class DailyBonusService
{
    public function checkAndAwardBonus(User $user): void
    {
        $profile = $user->getProfile();
        
        // Check if already awarded today
        if ($profile->last_activity_at?->isToday()) {
            return;
        }
        
        // Award daily bonus XP
        LFL::award('daily-xp')
            ->to($user)
            ->amount(10)
            ->save();
    }
}
```

### Event-Driven Awards

```php
// In EventServiceProvider
Event::listen(OrderCompleted::class, function ($event) {
    $xp = (int) ($event->order->total / 10); // 1 XP per $10 spent
    
    LFL::award('shopping-xp')
        ->to($event->order->user)
        ->amount($xp)
        ->save();
});

Event::listen(ReviewSubmitted::class, function ($event) {
    LFL::award('community-xp')
        ->to($event->review->user)
        ->amount(5)
        ->save();
    
    // Grant achievement for first review
    if (!$event->review->user->hasAchievement('first-review')) {
        LFL::grant('first-review')
            ->to($event->review->user)
            ->save();
    }
});
```
