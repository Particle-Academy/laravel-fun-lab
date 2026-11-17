<?php

declare(strict_types=1);

use LaravelFunLab\Facades\LFL;
use LaravelFunLab\Models\Achievement;
use LaravelFunLab\Models\AchievementGrant;
use LaravelFunLab\Models\MetricLevelGroup;
use LaravelFunLab\Models\ProfileMetric;
use LaravelFunLab\Models\ProfileMetricGroup;
use LaravelFunLab\Services\MetricLevelGroupService;
use LaravelFunLab\Tests\Fixtures\User;

/*
|--------------------------------------------------------------------------
| A group level is claimed exactly once
|--------------------------------------------------------------------------
|
| 0.7.0 made metric levels compare-and-set. Group levels still read the
| level, decided, and wrote it back, so two requests crossing the same group
| threshold both claimed it. These pin the group side to the same guarantee:
| a copy that is out of date never re-claims a level already recorded, and
| claims only the levels it has not seen.
|
*/

beforeEach(function () {
    LFL::setup(a: 'gamed-metric', with: ['slug' => 'combat']);
    LFL::setup(a: 'gamed-metric', with: ['slug' => 'crafting']);
    LFL::setup(a: 'metric-level-group', with: ['slug' => 'power']);
    LFL::setup(a: 'metric-level-group-metric', with: ['group' => 'power', 'metric' => 'combat']);
    LFL::setup(a: 'metric-level-group-metric', with: ['group' => 'power', 'metric' => 'crafting']);

    foreach ([[1, 0], [2, 100], [3, 200], [4, 300]] as [$level, $xp]) {
        LFL::setup(a: 'metric-level-group-level', with: ['group' => 'power', 'level' => $level, 'xp' => $xp]);
    }

    $this->group = MetricLevelGroup::findBySlug('power');
    $this->user = User::create(['name' => 'Group Claim', 'email' => 'group-claim@example.com']);
});

function staleGroupRow(MetricLevelGroup $group): ProfileMetricGroup
{
    return ProfileMetricGroup::where('metric_level_group_id', $group->id)->firstOrFail();
}

it('does not re-claim a group level from a stale copy', function () {
    LFL::award('combat')->to($this->user)->amount(10)->save();
    $stale = staleGroupRow($this->group);

    // Another request crosses group levels 2 and 3 and records them.
    LFL::award('crafting')->to($this->user)->amount(240)->save();

    $result = app(MetricLevelGroupService::class)->checkProgression($stale);

    expect($result['level_reached'])->toBeFalse()
        ->and($result['levels_unlocked'])->toBe([])
        ->and(ProfileMetricGroup::find($stale->id)->current_level)->toBe(3);
});

it('claims only the group levels a stale copy has not seen recorded', function () {
    LFL::award('combat')->to($this->user)->amount(10)->save();
    $stale = staleGroupRow($this->group);

    // Another request records group level 2.
    LFL::award('combat')->to($this->user)->amount(140)->save();

    // This process's own increment has landed; its group check has not run.
    ProfileMetric::where('profile_id', $this->user->getProfile()->id)->update(['total_xp' => 350]);

    $result = app(MetricLevelGroupService::class)->checkProgression($stale);

    expect(array_map(fn ($l) => $l->level, $result['levels_unlocked']))->toBe([3, 4])
        ->and($result['new_level'])->toBe(4)
        ->and(ProfileMetricGroup::find($stale->id)->current_level)->toBe(4);
});

it('grants a group level achievement once when progression runs twice', function () {
    LFL::setup(a: 'achievement', with: ['slug' => 'powerful']);
    $levelThree = $this->group->levels()->where('level', 3)->firstOrFail();
    $levelThree->achievements()->attach(Achievement::where('slug', 'powerful')->value('id'));

    LFL::award('combat')->to($this->user)->amount(10)->save();
    $stale = staleGroupRow($this->group);

    LFL::award('crafting')->to($this->user)->amount(240)->save();
    app(MetricLevelGroupService::class)->checkProgression($stale);

    expect(AchievementGrant::count())->toBe(1);
});
