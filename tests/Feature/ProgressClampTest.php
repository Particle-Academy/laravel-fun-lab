<?php

declare(strict_types=1);

use LaravelFunLab\Facades\LFL;
use LaravelFunLab\Services\MetricLevelGroupService;
use LaravelFunLab\Services\MetricLevelService;
use LaravelFunLab\Tests\Fixtures\User;

/*
|--------------------------------------------------------------------------
| Progress never goes below zero
|--------------------------------------------------------------------------
|
| Progress is measured from the current level's floor. A profile is level 1
| from its first award, even when level 1's own threshold is above its XP, so
| (xp - floor) was negative: 10 XP against levels at 50 and 80 reported
| -133.33%, which a progress bar draws as nothing or as garbage. Found by the
| fun-lab conformance export.
|
*/

beforeEach(function () {
    $this->user = User::create(['name' => 'Ada', 'email' => 'ada@example.com']);
});

it('clamps metric progress at 0 below the current level floor', function () {
    LFL::setup(a: 'gamed-metric', with: ['slug' => 'combat']);
    LFL::setup(a: 'metric-level', with: ['metric' => 'combat', 'level' => 1, 'xp' => 50]);
    LFL::setup(a: 'metric-level', with: ['metric' => 'combat', 'level' => 2, 'xp' => 80]);
    LFL::award('combat')->to($this->user)->amount(10)->save();

    expect(app(MetricLevelService::class)->getProgressPercentage($this->user, 'combat'))->toBe(0.0);
});

it('clamps group progress at 0 below the current level floor', function () {
    LFL::setup(a: 'gamed-metric', with: ['slug' => 'combat']);
    LFL::setup(a: 'metric-level-group', with: ['slug' => 'power']);
    LFL::setup(a: 'metric-level-group-metric', with: ['group' => 'power', 'metric' => 'combat']);
    LFL::setup(a: 'metric-level-group-level', with: ['group' => 'power', 'level' => 1, 'xp' => 50]);
    LFL::setup(a: 'metric-level-group-level', with: ['group' => 'power', 'level' => 2, 'xp' => 80]);
    LFL::award('combat')->to($this->user)->amount(10)->save();

    expect(app(MetricLevelGroupService::class)->getProgressPercentage($this->user, 'power'))->toBe(0.0);
});

it('still reports partial progress above the floor', function () {
    LFL::setup(a: 'gamed-metric', with: ['slug' => 'combat']);
    LFL::setup(a: 'metric-level', with: ['metric' => 'combat', 'level' => 1, 'xp' => 0]);
    LFL::setup(a: 'metric-level', with: ['metric' => 'combat', 'level' => 2, 'xp' => 80]);
    LFL::award('combat')->to($this->user)->amount(20)->save();

    expect(app(MetricLevelService::class)->getProgressPercentage($this->user, 'combat'))->toBe(25.0);
});
