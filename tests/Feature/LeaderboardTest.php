<?php

declare(strict_types=1);

use Illuminate\Pagination\LengthAwarePaginator;
use LaravelFunLab\Facades\LFL;
use LaravelFunLab\Models\GamedMetric;
use LaravelFunLab\Tests\Fixtures\User;

/*
|--------------------------------------------------------------------------
| Leaderboard Tests
|--------------------------------------------------------------------------
|
| Tests for leaderboard building, filtering, sorting, pagination, and
| opt-out exclusion. Covers all leaderboard functionality.
|
*/

describe('LeaderboardBuilder', function () {

    beforeEach(function () {
        // Create a default GamedMetric for XP tests using LFL::setup()
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

    it('returns a leaderboard builder instance', function () {
        $builder = LFL::leaderboard();

        expect($builder)->toBeInstanceOf(\LaravelFunLab\Builders\LeaderboardBuilder::class);
    });

    it('can filter by awardable type', function () {
        $user1 = User::create(['name' => 'User 1', 'email' => 'user1@example.com']);
        $user2 = User::create(['name' => 'User 2', 'email' => 'user2@example.com']);

        $profile1 = $user1->getProfile();
        $profile2 = $user2->getProfile();

        $profile1->setAggregates(['total_xp' => 100]);
        $profile2->setAggregates(['total_xp' => 50]);

        $leaderboard = LFL::leaderboard()
            ->for(User::class)
            ->get();

        expect($leaderboard)->toHaveCount(2)
            ->and($leaderboard->first()->awardable_type)->toBe(User::class);
    });

    it('sorts by XP by default', function () {
        $user1 = User::create(['name' => 'User 1', 'email' => 'user1@example.com']);
        $user2 = User::create(['name' => 'User 2', 'email' => 'user2@example.com']);
        $user3 = User::create(['name' => 'User 3', 'email' => 'user3@example.com']);

        $profile1 = $user1->getProfile();
        $profile2 = $user2->getProfile();
        $profile3 = $user3->getProfile();

        $profile1->setAggregates(['total_xp' => 50]);
        $profile2->setAggregates(['total_xp' => 100]);
        $profile3->setAggregates(['total_xp' => 75]);

        $leaderboard = LFL::leaderboard()
            ->for(User::class)
            ->get();

        expect($leaderboard->first()->total_xp)->toBe(100)
            ->and($leaderboard->last()->total_xp)->toBe(50);
    });

    it('can sort by achievements', function () {
        $user1 = User::create(['name' => 'User 1', 'email' => 'user1@example.com']);
        $user2 = User::create(['name' => 'User 2', 'email' => 'user2@example.com']);

        $profile1 = $user1->getProfile();
        $profile2 = $user2->getProfile();

        $profile1->setAggregates(['achievement_count' => 5]);
        $profile2->setAggregates(['achievement_count' => 10]);

        $leaderboard = LFL::leaderboard()
            ->for(User::class)
            ->by('achievements')
            ->get();

        expect($leaderboard->first()->achievement_count)->toBe(10)
            ->and($leaderboard->last()->achievement_count)->toBe(5);
    });

    it('can sort by prizes', function () {
        $user1 = User::create(['name' => 'User 1', 'email' => 'user1@example.com']);
        $user2 = User::create(['name' => 'User 2', 'email' => 'user2@example.com']);

        $profile1 = $user1->getProfile();
        $profile2 = $user2->getProfile();

        $profile1->setAggregates(['prize_count' => 2]);
        $profile2->setAggregates(['prize_count' => 5]);

        $leaderboard = LFL::leaderboard()
            ->for(User::class)
            ->by('prizes')
            ->get();

        expect($leaderboard->first()->prize_count)->toBe(5)
            ->and($leaderboard->last()->prize_count)->toBe(2);
    });

    it('excludes opted-out profiles by default', function () {
        $user1 = User::create(['name' => 'User 1', 'email' => 'user1@example.com']);
        $user2 = User::create(['name' => 'User 2', 'email' => 'user2@example.com']);

        $profile1 = $user1->getProfile();
        $profile2 = $user2->getProfile();

        $profile1->setAggregates(['total_xp' => 100]);
        $profile2->setAggregates(['total_xp' => 50]);
        $profile2->update(['is_opted_in' => false]);

        $leaderboard = LFL::leaderboard()
            ->for(User::class)
            ->get();

        expect($leaderboard)->toHaveCount(1)
            ->and($leaderboard->first()->total_xp)->toBe(100);
    });

    it('can include opted-out profiles when explicitly requested', function () {
        $user1 = User::create(['name' => 'User 1', 'email' => 'user1@example.com']);
        $user2 = User::create(['name' => 'User 2', 'email' => 'user2@example.com']);

        $profile1 = $user1->getProfile();
        $profile2 = $user2->getProfile();

        $profile1->setAggregates(['total_xp' => 100]);
        $profile2->setAggregates(['total_xp' => 50]);
        $profile2->update(['is_opted_in' => false]);

        $leaderboard = LFL::leaderboard()
            ->for(User::class)
            ->excludeOptedOut(false)
            ->get();

        expect($leaderboard)->toHaveCount(2);
    });

    it('adds rank to leaderboard results', function () {
        $user1 = User::create(['name' => 'User 1', 'email' => 'user1@example.com']);
        $user2 = User::create(['name' => 'User 2', 'email' => 'user2@example.com']);
        $user3 = User::create(['name' => 'User 3', 'email' => 'user3@example.com']);

        $profile1 = $user1->getProfile();
        $profile2 = $user2->getProfile();
        $profile3 = $user3->getProfile();

        $profile1->setAggregates(['total_xp' => 50]);
        $profile2->setAggregates(['total_xp' => 100]);
        $profile3->setAggregates(['total_xp' => 75]);

        $leaderboard = LFL::leaderboard()
            ->for(User::class)
            ->get();

        expect($leaderboard->first()->rank)->toBe(1)
            ->and($leaderboard->get(1)->rank)->toBe(2)
            ->and($leaderboard->last()->rank)->toBe(3);
    });

    it('can paginate leaderboard results', function () {
        // Create 5 users with profiles
        $users = [];
        for ($i = 1; $i <= 5; $i++) {
            $users[] = User::create(['name' => "User {$i}", 'email' => "user{$i}@example.com"]);
            $users[$i - 1]->getProfile()->setAggregates(['total_xp' => $i * 10]);
        }

        $paginator = LFL::leaderboard()
            ->for(User::class)
            ->perPage(2)
            ->page(1)
            ->paginate();

        expect($paginator)->toBeInstanceOf(LengthAwarePaginator::class)
            ->and($paginator->count())->toBe(2)
            ->and($paginator->total())->toBe(5)
            ->and($paginator->currentPage())->toBe(1);
    });

    it('calculates ranks correctly with pagination', function () {
        // Create 5 users with profiles
        $users = [];
        for ($i = 1; $i <= 5; $i++) {
            $users[] = User::create(['name' => "User {$i}", 'email' => "user{$i}@example.com"]);
            $users[$i - 1]->getProfile()->setAggregates(['total_xp' => $i * 10]);
        }

        $paginator = LFL::leaderboard()
            ->for(User::class)
            ->perPage(2)
            ->page(2)
            ->paginate();

        // Page 2 should have ranks 3 and 4
        expect($paginator->first()->rank)->toBe(3)
            ->and($paginator->last()->rank)->toBe(4);
    });

    it('can take a limited number of results', function () {
        $users = [];
        for ($i = 1; $i <= 5; $i++) {
            $users[] = User::create(['name' => "User {$i}", 'email' => "user{$i}@example.com"]);
            $users[$i - 1]->getProfile()->setAggregates(['total_xp' => $i * 10]);
        }

        $leaderboard = LFL::leaderboard()
            ->for(User::class)
            ->take(3);

        expect($leaderboard)->toHaveCount(3)
            ->and($leaderboard->first()->rank)->toBe(1)
            ->and($leaderboard->last()->rank)->toBe(3);
    });

    describe('Time-based filtering', function () {

        it('filters by daily period', function () {
            $user1 = User::create(['name' => 'User 1', 'email' => 'user1@example.com']);
            $user2 = User::create(['name' => 'User 2', 'email' => 'user2@example.com']);

            $profile1 = $user1->getProfile();
            $profile2 = $user2->getProfile();

            // Award XP today using LFL::award()
            LFL::award('general-xp')->to($user1)->amount(50)->save();
            LFL::award('general-xp')->to($user2)->amount(30)->save();

            $leaderboard = LFL::leaderboard()
                ->for(User::class)
                ->period('daily')
                ->get();

            // Should include today's XP
            expect($leaderboard)->toHaveCount(2)
                ->and($leaderboard->first()->awardable_id)->toBe($user1->id);
        });

        it('filters by weekly period', function () {
            $user1 = User::create(['name' => 'User 1', 'email' => 'user1@example.com']);
            $user2 = User::create(['name' => 'User 2', 'email' => 'user2@example.com']);

            $profile1 = $user1->getProfile();
            $profile2 = $user2->getProfile();

            // Award XP this week using LFL::award()
            LFL::award('general-xp')->to($user1)->amount(50)->save();
            LFL::award('general-xp')->to($user2)->amount(30)->save();

            $leaderboard = LFL::leaderboard()
                ->for(User::class)
                ->period('weekly')
                ->get();

            expect($leaderboard)->toHaveCount(2);
        });

        it('filters by monthly period', function () {
            $user1 = User::create(['name' => 'User 1', 'email' => 'user1@example.com']);
            $user2 = User::create(['name' => 'User 2', 'email' => 'user2@example.com']);

            $profile1 = $user1->getProfile();
            $profile2 = $user2->getProfile();

            // Award XP this month using LFL::award()
            LFL::award('general-xp')->to($user1)->amount(50)->save();
            LFL::award('general-xp')->to($user2)->amount(30)->save();

            $leaderboard = LFL::leaderboard()
                ->for(User::class)
                ->period('monthly')
                ->get();

            expect($leaderboard)->toHaveCount(2);
        });

        it('uses all-time when period is null or all-time', function () {
            $user1 = User::create(['name' => 'User 1', 'email' => 'user1@example.com']);
            $user2 = User::create(['name' => 'User 2', 'email' => 'user2@example.com']);

            $profile1 = $user1->getProfile();
            $profile2 = $user2->getProfile();

            $profile1->setAggregates(['total_xp' => 100]);
            $profile2->setAggregates(['total_xp' => 50]);

            $leaderboard1 = LFL::leaderboard()
                ->for(User::class)
                ->period(null)
                ->get();

            $leaderboard2 = LFL::leaderboard()
                ->for(User::class)
                ->period('all-time')
                ->get();

            expect($leaderboard1->first()->total_xp)->toBe(100)
                ->and($leaderboard2->first()->total_xp)->toBe(100);
        });

    });

});
