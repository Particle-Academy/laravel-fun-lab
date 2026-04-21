<?php

declare(strict_types=1);

use Carbon\Carbon;
use LaravelFunLab\Facades\LFL;
use LaravelFunLab\Models\Achievement;
use LaravelFunLab\Models\GamedMetric;
use LaravelFunLab\Models\Profile;
use LaravelFunLab\Models\ProfileMetric;
use LaravelFunLab\Tests\Fixtures\User;

/*
|--------------------------------------------------------------------------
| Analytics Tests
|--------------------------------------------------------------------------
|
| Tests for the analytics builder and query methods.
| Covers aggregations, time-series queries, filtering, and export functionality.
|
*/

describe('AnalyticsBuilder', function () {

    it('can access analytics via LFL facade', function () {
        $builder = LFL::analytics();

        expect($builder)->toBeInstanceOf(\LaravelFunLab\Builders\AnalyticsBuilder::class);
    });

    it('returns a query builder instance', function () {
        $query = LFL::analytics()->query();

        expect($query)->toBeInstanceOf(\Illuminate\Database\Eloquent\Builder::class);
    });

});

describe('Analytics Filtering', function () {

    beforeEach(function () {
        $this->user1 = User::create(['name' => 'User 1', 'email' => 'user1@example.com']);
        $this->user2 = User::create(['name' => 'User 2', 'email' => 'user2@example.com']);

        // Create a GamedMetric for XP using LFL::setup()
        LFL::setup(
            a: 'gamed-metric',
            with: [
                'slug' => 'general-xp',
                'name' => 'General XP',
                'description' => 'General experience points',
                'active' => true,
            ]
        );

        // Create some XP awards using LFL::award()
        LFL::award('general-xp')->to($this->user1)->amount(50)->save();
        LFL::award('general-xp')->to($this->user2)->amount(30)->save();
        LFL::award('general-xp')->to($this->user1)->amount(20)->save();
    });

    it('can count total XP awarded', function () {
        $totalXp = ProfileMetric::sum('total_xp');

        // user1: 50 + 20 = 70, user2: 30 = 30, total = 100
        expect($totalXp)->toBe(100);
    });

    it('can filter by awardable type', function () {
        $profiles = Profile::forAwardableType(User::class)->count();

        expect($profiles)->toBe(2);
    });

    it('can count profiles with XP', function () {
        $count = Profile::where('total_xp', '>', 0)->count();

        expect($count)->toBe(2);
    });

    it('can filter by date period', function () {
        // Both profile metrics are recent (created in beforeEach)
        $recentCount = ProfileMetric::where('updated_at', '>=', Carbon::now()->startOfWeek())->count();

        // Both metrics should be recent
        expect($recentCount)->toBe(2);
    });

});

describe('Analytics Aggregations', function () {

    beforeEach(function () {
        $this->user1 = User::create(['name' => 'User 1', 'email' => 'user1@example.com']);
        $this->user2 = User::create(['name' => 'User 2', 'email' => 'user2@example.com']);

        // Create a GamedMetric for XP using LFL::setup()
        LFL::setup(
            a: 'gamed-metric',
            with: [
                'slug' => 'general-xp',
                'name' => 'General XP',
                'description' => 'General experience points',
                'active' => true,
            ]
        );

        // Award XP using LFL::award()
        LFL::award('general-xp')->to($this->user1)->amount(50)->save();
        LFL::award('general-xp')->to($this->user2)->amount(30)->save();
        LFL::award('general-xp')->to($this->user1)->amount(20)->save();
    });

    it('can count total profiles', function () {
        $count = Profile::count();

        expect($count)->toBe(2);
    });

    it('can calculate total XP', function () {
        $total = Profile::sum('total_xp');

        expect($total)->toBe(100);
    });

    it('can calculate average XP', function () {
        $average = Profile::avg('total_xp');

        expect($average)->toBe(50.0);
    });

    it('can find minimum XP', function () {
        $min = Profile::min('total_xp');

        expect($min)->toBe(30);
    });

    it('can find maximum XP', function () {
        $max = Profile::max('total_xp');

        expect($max)->toBe(70);
    });

});

describe('Active Users Analytics', function () {

    beforeEach(function () {
        // Create a GamedMetric for XP using LFL::setup()
        LFL::setup(
            a: 'gamed-metric',
            with: [
                'slug' => 'general-xp',
                'name' => 'General XP',
                'description' => 'General experience points',
                'active' => true,
            ]
        );
    });

    it('can count active users', function () {
        $user1 = User::create(['name' => 'User 1', 'email' => 'user1@example.com']);
        $user2 = User::create(['name' => 'User 2', 'email' => 'user2@example.com']);
        $user3 = User::create(['name' => 'User 3', 'email' => 'user3@example.com']);

        // Award XP using LFL::award()
        LFL::award('general-xp')->to($user1)->amount(10)->save();
        LFL::award('general-xp')->to($user2)->amount(20)->save();
        // user3 has no XP

        $activeUsers = Profile::where('total_xp', '>', 0)->count();

        expect($activeUsers)->toBe(2);
    });

    it('can count active users in a period', function () {
        $user1 = User::create(['name' => 'User 1', 'email' => 'user1@example.com']);
        $user2 = User::create(['name' => 'User 2', 'email' => 'user2@example.com']);

        // Award XP using LFL::award()
        LFL::award('general-xp')->to($user1)->amount(10)->save();
        LFL::award('general-xp')->to($user2)->amount(20)->save();

        // Update one profile to be old
        $profile = Profile::first();
        $profile->setAggregates(['last_activity_at' => Carbon::now()->subDays(10)]);

        $recentlyActive = Profile::where('last_activity_at', '>=', Carbon::now()->startOfWeek())->count();

        expect($recentlyActive)->toBe(1);
    });

});

describe('Achievement Completion Rate', function () {

    beforeEach(function () {
        // Create a GamedMetric for XP using LFL::setup()
        LFL::setup(
            a: 'gamed-metric',
            with: [
                'slug' => 'general-xp',
                'name' => 'General XP',
                'description' => 'General experience points',
                'active' => true,
            ]
        );
    });

    it('can calculate achievement completion rate', function () {
        $user1 = User::create(['name' => 'User 1', 'email' => 'user1@example.com']);
        $user2 = User::create(['name' => 'User 2', 'email' => 'user2@example.com']);

        // Create profiles
        $user1->getProfile();
        $user2->getProfile();

        // Create achievement using LFL::setup()
        LFL::setup(a: 'achievement', with: ['slug' => 'first-login', 'name' => 'First Login']);

        // Grant to one user using LFL::grant()
        LFL::grant('first-login')->to($user1)->save();

        // Refresh profiles to get updated counts
        $user1->refresh();

        // Calculate completion rate
        $totalProfiles = Profile::count();
        $profilesWithAchievement = Profile::where('achievement_count', '>', 0)->count();

        $completionRate = ($profilesWithAchievement / $totalProfiles) * 100;

        expect($totalProfiles)->toBe(2)
            ->and($profilesWithAchievement)->toBe(1)
            ->and($completionRate)->toBe(50.0);
    });

});

describe('Export Functionality', function () {

    beforeEach(function () {
        // Create a GamedMetric for XP using LFL::setup()
        LFL::setup(
            a: 'gamed-metric',
            with: [
                'slug' => 'general-xp',
                'name' => 'General XP',
                'description' => 'General experience points',
                'active' => true,
            ]
        );
    });

    it('can export profile data', function () {
        $user1 = User::create(['name' => 'User 1', 'email' => 'user1@example.com']);
        $user2 = User::create(['name' => 'User 2', 'email' => 'user2@example.com']);

        // Award XP using LFL::award()
        LFL::award('general-xp')->to($user1)->amount(50)->save();
        LFL::award('general-xp')->to($user2)->amount(30)->save();

        $data = Profile::all()->map(function ($profile) {
            return [
                'awardable_type' => $profile->awardable_type,
                'awardable_id' => $profile->awardable_id,
                'total_xp' => $profile->total_xp,
                'achievement_count' => $profile->achievement_count,
                'prize_count' => $profile->prize_count,
            ];
        })->toArray();

        expect($data)->toHaveCount(2)
            ->and($data[0])->toHaveKeys(['awardable_type', 'awardable_id', 'total_xp', 'achievement_count', 'prize_count']);
    });

});
