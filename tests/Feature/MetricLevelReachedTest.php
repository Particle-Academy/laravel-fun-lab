<?php

declare(strict_types=1);

use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Support\Facades\Event;
use LaravelFunLab\Events\MetricLevelReached;
use LaravelFunLab\Facades\LFL;
use LaravelFunLab\Models\Achievement;
use LaravelFunLab\Models\GamedMetric;
use LaravelFunLab\Models\MetricLevel;
use LaravelFunLab\Models\ProfileMetric;
use LaravelFunLab\Services\MetricLevelService;
use LaravelFunLab\Tests\Fixtures\User;

/*
|--------------------------------------------------------------------------
| MetricLevelReached
|--------------------------------------------------------------------------
|
| One event per level crossed, in order, including several crossed by a
| single award; never again for a level already recorded, however often
| progression is recomputed.
|
*/

beforeEach(function () {
    $this->metric = GamedMetric::create(['slug' => 'combat', 'name' => 'Combat', 'active' => true]);

    foreach ([[1, 0, 'Novice'], [2, 100, 'Apprentice'], [3, 200, 'Adept'], [4, 300, 'Expert']] as [$level, $xp, $name]) {
        MetricLevel::create([
            'gamed_metric_id' => $this->metric->id,
            'level' => $level,
            'xp_threshold' => $xp,
            'name' => $name,
        ]);
    }

    $this->badge = Achievement::create(['slug' => 'adept', 'name' => 'Adept', 'is_active' => true]);
    MetricLevel::where('level', 3)->first()->achievements()->attach($this->badge->id);

    $this->user = User::create(['name' => 'Lev', 'email' => 'lev@example.com']);
});

it('fires once for a single level crossed', function () {
    Event::fake([MetricLevelReached::class]);

    LFL::award('combat')->to($this->user)->amount(150)->save();

    Event::assertDispatchedTimes(MetricLevelReached::class, 1);
    Event::assertDispatched(MetricLevelReached::class, fn (MetricLevelReached $e) => $e->level->level === 2
        && $e->gamedMetric->is($this->metric)
        && $e->profile->awardable_id === $this->user->id
        && $e->achievements === []);
});

it('fires once per level when one award crosses several, in ascending order', function () {
    $seen = [];
    Event::listen(MetricLevelReached::class, function (MetricLevelReached $e) use (&$seen) {
        $seen[] = $e->level->level;
    });

    LFL::award('combat')->to($this->user)->amount(350)->save();

    expect($seen)->toBe([2, 3, 4]);
});

it('carries the achievements granted for that level, and only that level', function () {
    $byLevel = [];
    Event::listen(MetricLevelReached::class, function (MetricLevelReached $e) use (&$byLevel) {
        $byLevel[$e->level->level] = array_map(fn (Achievement $a) => $a->slug, $e->achievements);
    });

    LFL::award('combat')->to($this->user)->amount(350)->save();

    expect($byLevel)->toBe([2 => [], 3 => ['adept'], 4 => []])
        ->and($this->user->hasAchievement('adept'))->toBeTrue();
});

it('does not list an achievement the profile already held', function () {
    LFL::grant('adept')->to($this->user)->save();

    $byLevel = [];
    Event::listen(MetricLevelReached::class, function (MetricLevelReached $e) use (&$byLevel) {
        $byLevel[$e->level->level] = count($e->achievements);
    });

    LFL::award('combat')->to($this->user)->amount(250)->save();

    expect($byLevel)->toBe([2 => 0, 3 => 0]);
});

it('does not fire when no level is crossed', function () {
    Event::fake([MetricLevelReached::class]);

    LFL::award('combat')->to($this->user)->amount(50)->save();

    Event::assertNotDispatched(MetricLevelReached::class);
});

it('does not fire again when progression is recomputed', function () {
    LFL::award('combat')->to($this->user)->amount(250)->save();

    Event::fake([MetricLevelReached::class]);
    $row = ProfileMetric::where('gamed_metric_id', $this->metric->id)->firstOrFail();

    app(MetricLevelService::class)->checkProgression($row);
    app(MetricLevelService::class)->checkProgression($row->fresh());

    Event::assertNotDispatched(MetricLevelReached::class);
});

it('does not fire from a stale copy after another process advanced the level', function () {
    LFL::award('combat')->to($this->user)->amount(10)->save();
    $stale = ProfileMetric::where('gamed_metric_id', $this->metric->id)->firstOrFail();

    // Another request crosses level 2 and 3 and records it.
    LFL::award('combat')->to($this->user)->amount(240)->save();

    Event::fake([MetricLevelReached::class]);

    // The stale copy still says level 1 but the XP read is current.
    $stale->total_xp = 250;
    $result = app(MetricLevelService::class)->checkProgression($stale);

    Event::assertNotDispatched(MetricLevelReached::class);
    expect($result['level_reached'])->toBeFalse()
        ->and(ProfileMetric::find($stale->id)->current_level)->toBe(3);
});

it('fires only for the levels a stale copy has not already seen recorded', function () {
    LFL::award('combat')->to($this->user)->amount(10)->save();
    $stale = ProfileMetric::where('gamed_metric_id', $this->metric->id)->firstOrFail();

    // Another request records level 2.
    LFL::award('combat')->to($this->user)->amount(140)->save();

    $seen = [];
    Event::listen(MetricLevelReached::class, function (MetricLevelReached $e) use (&$seen) {
        $seen[] = $e->level->level;
    });

    // This process's own increment has landed; its level check has not run.
    ProfileMetric::whereKey($stale->id)->update(['total_xp' => 350]);
    $stale->total_xp = 350;
    app(MetricLevelService::class)->checkProgression($stale);

    expect($seen)->toBe([3, 4])
        ->and(ProfileMetric::find($stale->id)->current_level)->toBe(4);
});

it('respects lfl.events.dispatch', function () {
    config(['lfl.events.dispatch' => false]);
    Event::fake([MetricLevelReached::class]);

    LFL::award('combat')->to($this->user)->amount(350)->save();

    Event::assertNotDispatched(MetricLevelReached::class);
    expect($this->user->getLevelFor('combat'))->toBe(4);
});

it('broadcasts the way XpAwarded does', function () {
    $captured = null;
    Event::listen(MetricLevelReached::class, function (MetricLevelReached $e) use (&$captured) {
        if ($e->level->level === 3) {
            $captured = $e;
        }
    });

    LFL::award('combat')->to($this->user)->amount(250)->save();

    expect($captured)->toBeInstanceOf(ShouldBroadcast::class)
        ->and($captured->broadcastAs())->toBe('metric.level-reached');

    $channels = $captured->broadcastOn();
    expect($channels)->toHaveCount(1)
        ->and($channels[0])->toBeInstanceOf(PrivateChannel::class)
        ->and($channels[0]->name)->toBe('private-lfl.profile.'.str_replace('\\', '.', User::class).'.'.$this->user->id);

    config(['lfl.events.broadcast' => false]);
    expect($captured->broadcastWhen())->toBeFalse();
    config(['lfl.events.broadcast' => true]);
    expect($captured->broadcastWhen())->toBeTrue();

    $payload = $captured->broadcastWith();
    expect($payload)->toMatchArray([
        'profile_id' => $captured->profile->id,
        'gamed_metric_id' => $this->metric->id,
        'gamed_metric_slug' => 'combat',
        'gamed_metric_name' => 'Combat',
        'level' => 3,
        'level_name' => 'Adept',
        'xp_threshold' => 200,
        'total_xp' => 250,
    ])->and($payload['achievements'])->toBe([
        ['id' => $this->badge->id, 'slug' => 'adept', 'name' => 'Adept', 'icon' => null],
    ])->and($payload)->toHaveKey('occurred_at');
});
