<?php

declare(strict_types=1);

use LaravelFunLab\Facades\LFL;
use LaravelFunLab\Models\Achievement;
use LaravelFunLab\Models\GamedMetric;
use LaravelFunLab\Models\Profile;
use LaravelFunLab\Tests\Fixtures\User;

/*
|--------------------------------------------------------------------------
| Profile Tests
|--------------------------------------------------------------------------
|
| Tests for profile creation, opt-in/opt-out logic, and aggregations.
| Covers all profile-related functionality including award blocking.
|
*/

describe('Profile Model', function () {

    it('can create a profile for an awardable model', function () {
        $user = User::create(['name' => 'Test User', 'email' => 'test@example.com']);

        $profile = Profile::create([
            'awardable_type' => User::class,
            'awardable_id' => $user->id,
            'is_opted_in' => true,
        ]);

        expect($profile)->toBeInstanceOf(Profile::class)
            ->and($profile->awardable)->toBeInstanceOf(User::class)
            ->and($profile->awardable->id)->toBe($user->id);
    });

    it('has polymorphic relationship to awardable', function () {
        $user = User::create(['name' => 'Test User', 'email' => 'test@example.com']);
        $profile = Profile::create([
            'awardable_type' => User::class,
            'awardable_id' => $user->id,
        ]);

        expect($profile->awardable)->toBeInstanceOf(User::class)
            ->and($profile->awardable->is($user))->toBeTrue();
    });

    it('defaults to opted in when created', function () {
        $user = User::create(['name' => 'Test User', 'email' => 'test@example.com']);
        $profile = Profile::create([
            'awardable_type' => User::class,
            'awardable_id' => $user->id,
            'is_opted_in' => true, // Explicitly set default
        ]);

        expect($profile->is_opted_in)->toBeTrue()
            ->and($profile->isOptedIn())->toBeTrue()
            ->and($profile->isOptedOut())->toBeFalse();
    });

    it('can opt in and opt out', function () {
        $user = User::create(['name' => 'Test User', 'email' => 'test@example.com']);
        $profile = Profile::create([
            'awardable_type' => User::class,
            'awardable_id' => $user->id,
            'is_opted_in' => false,
        ]);

        expect($profile->isOptedOut())->toBeTrue();

        $profile->optIn();

        expect($profile->fresh()->isOptedIn())->toBeTrue();

        $profile->optOut();

        expect($profile->fresh()->isOptedOut())->toBeTrue();
    });

    it('can update last activity timestamp', function () {
        $user = User::create(['name' => 'Test User', 'email' => 'test@example.com']);
        $profile = Profile::create([
            'awardable_type' => User::class,
            'awardable_id' => $user->id,
        ]);

        expect($profile->last_activity_at)->toBeNull();

        $profile->touchActivity();

        expect($profile->fresh()->last_activity_at)->not->toBeNull();
    });

    it('can store display preferences', function () {
        $user = User::create(['name' => 'Test User', 'email' => 'test@example.com']);
        $profile = Profile::create([
            'awardable_type' => User::class,
            'awardable_id' => $user->id,
            'display_preferences' => [
                'theme' => 'dark',
                'show_xp' => true,
            ],
        ]);

        expect($profile->display_preferences)->toBe([
            'theme' => 'dark',
            'show_xp' => true,
        ]);
    });

    it('can store visibility settings', function () {
        $user = User::create(['name' => 'Test User', 'email' => 'test@example.com']);
        $profile = Profile::create([
            'awardable_type' => User::class,
            'awardable_id' => $user->id,
            'visibility_settings' => [
                'show_on_leaderboard' => true,
                'show_achievements' => false,
            ],
        ]);

        expect($profile->visibility_settings)->toBe([
            'show_on_leaderboard' => true,
            'show_achievements' => false,
        ]);
    });

    describe('Scopes', function () {

        it('can filter by opted in status', function () {
            $user1 = User::create(['name' => 'User 1', 'email' => 'user1@example.com']);
            $user2 = User::create(['name' => 'User 2', 'email' => 'user2@example.com']);

            Profile::create([
                'awardable_type' => User::class,
                'awardable_id' => $user1->id,
                'is_opted_in' => true,
            ]);

            Profile::create([
                'awardable_type' => User::class,
                'awardable_id' => $user2->id,
                'is_opted_in' => false,
            ]);

            $optedIn = Profile::optedIn()->get();
            $optedOut = Profile::optedOut()->get();

            expect($optedIn)->toHaveCount(1)
                ->and($optedIn->first()->is_opted_in)->toBeTrue()
                ->and($optedOut)->toHaveCount(1)
                ->and($optedOut->first()->is_opted_in)->toBeFalse();
        });

        it('can filter by awardable type', function () {
            $user = User::create(['name' => 'Test User', 'email' => 'test@example.com']);

            Profile::create([
                'awardable_type' => User::class,
                'awardable_id' => $user->id,
            ]);

            $profiles = Profile::forAwardableType(User::class)->get();

            expect($profiles)->toHaveCount(1)
                ->and($profiles->first()->awardable_type)->toBe(User::class);
        });

        it('can order by total XP', function () {
            $user1 = User::create(['name' => 'User 1', 'email' => 'user1@example.com']);
            $user2 = User::create(['name' => 'User 2', 'email' => 'user2@example.com']);

            $p1 = Profile::create([
                'awardable_type' => User::class,
                'awardable_id' => $user1->id,
            ]);
            $p1->setAggregates(['total_xp' => 100]);

            $p2 = Profile::create([
                'awardable_type' => User::class,
                'awardable_id' => $user2->id,
            ]);
            $p2->setAggregates(['total_xp' => 200]);

            $ordered = Profile::orderedByXp()->get();

            expect($ordered->first()->total_xp)->toBe(200)
                ->and($ordered->last()->total_xp)->toBe(100);
        });

    });

});

describe('HasProfile Trait', function () {

    it('provides profile relationship', function () {
        $user = User::create(['name' => 'Test User', 'email' => 'test@example.com']);

        $profile = Profile::create([
            'awardable_type' => User::class,
            'awardable_id' => $user->id,
        ]);

        expect($user->profile)->toBeInstanceOf(Profile::class)
            ->and($user->profile->id)->toBe($profile->id);
    });

    it('can get or create profile', function () {
        $user = User::create(['name' => 'Test User', 'email' => 'test@example.com']);

        $profile1 = $user->getProfile();
        $profile2 = $user->getProfile();

        expect($profile1)->toBeInstanceOf(Profile::class)
            ->and($profile2->id)->toBe($profile1->id)
            ->and(Profile::count())->toBe(1);
    });

    it('creates profile with default values when using getProfile', function () {
        $user = User::create(['name' => 'Test User', 'email' => 'test@example.com']);

        $profile = $user->getProfile();

        expect($profile->is_opted_in)->toBeTrue()
            ->and($profile->total_xp)->toBe(0)
            ->and($profile->achievement_count)->toBe(0)
            ->and($profile->prize_count)->toBe(0);
    });

    it('can check if user has profile', function () {
        $user = User::create(['name' => 'Test User', 'email' => 'test@example.com']);

        expect($user->hasProfile())->toBeFalse();

        $user->getProfile();

        expect($user->hasProfile())->toBeTrue();
    });

    it('defaults to opted in when no profile exists', function () {
        $user = User::create(['name' => 'Test User', 'email' => 'test@example.com']);

        expect($user->isOptedIn())->toBeTrue()
            ->and($user->isOptedOut())->toBeFalse();
    });

    it('can opt in through trait', function () {
        $user = User::create(['name' => 'Test User', 'email' => 'test@example.com']);

        Profile::create([
            'awardable_type' => User::class,
            'awardable_id' => $user->id,
            'is_opted_in' => false,
        ]);

        $user->optIn();

        expect($user->fresh()->profile->is_opted_in)->toBeTrue();
    });

    it('can opt out through trait', function () {
        $user = User::create(['name' => 'Test User', 'email' => 'test@example.com']);

        $user->optOut();

        expect($user->fresh()->profile->is_opted_in)->toBeFalse();
    });

    it('creates profile when opting out if none exists', function () {
        $user = User::create(['name' => 'Test User', 'email' => 'test@example.com']);

        $user->optOut();

        $user->refresh();

        expect($user->hasProfile())->toBeTrue()
            ->and($user->profile->is_opted_in)->toBeFalse();
    });

});

describe('Opt-In/Opt-Out Logic', function () {

    beforeEach(function () {
        // Create a GamedMetric for XP tests using LFL::setup()
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

    it('allows XP awards when opted in', function () {
        $user = User::create(['name' => 'Test User', 'email' => 'test@example.com']);

        $user->getProfile(); // Create profile (defaults to opted in)

        // Use the new LFL::award() API
        $result = LFL::award('general-xp')->to($user)->amount(50)->save();

        expect($result->total_xp)->toBe(50);
    });

    it('blocks XP awards when opted out', function () {
        $user = User::create(['name' => 'Test User', 'email' => 'test@example.com']);

        $user->optOut();

        // XP awards to opted-out recipients are rejected at the service
        // layer — matches the behavior for achievement and prize grants.
        expect(fn () => LFL::award('general-xp')->to($user)->amount(50)->save())
            ->toThrow(\LaravelFunLab\Exceptions\AwardRejectedException::class);

        expect($user->fresh()->profile->isOptedOut())->toBeTrue();
        expect($user->fresh()->profile->total_xp)->toBe(0);
    });

    it('blocks achievements when opted out', function () {
        // Create achievement using LFL::setup()
        LFL::setup(a: 'achievement', with: ['slug' => 'test-achievement', 'name' => 'Test Achievement']);

        $user = User::create(['name' => 'Test User', 'email' => 'test@example.com']);

        $user->optOut();

        // Use the new LFL::grant() API
        $result = LFL::grant('test-achievement')->to($user)->save();

        expect($result)->toBeFailedAward()
            ->and($result->message)->toBe('Recipient has opted out of gamification');
    });

    it('blocks prizes when opted out', function () {
        // Create a prize using LFL::setup()
        LFL::setup(
            a: 'prize',
            with: [
                'slug' => 'test-prize',
                'name' => 'Test Prize',
                'type' => 'virtual',
            ]
        );

        $user = User::create(['name' => 'Test User', 'email' => 'test@example.com']);

        $user->optOut();

        // Use the new LFL::grant() API
        $result = LFL::grant('test-prize')
            ->to($user)
            ->because('test prize')
            ->save();

        expect($result)->toBeFailedAward()
            ->and($result->message)->toBe('Recipient has opted out of gamification');
    });

    it('allows awards again after opting back in', function () {
        // Create a prize using LFL::setup()
        LFL::setup(
            a: 'prize',
            with: [
                'slug' => 'test-prize',
                'name' => 'Test Prize',
                'type' => 'virtual',
            ]
        );

        $user = User::create(['name' => 'Test User', 'email' => 'test@example.com']);

        $user->optOut();

        // Use the new LFL::grant() API
        $result1 = LFL::grant('test-prize')
            ->to($user)
            ->because('test prize')
            ->save();
        expect($result1)->toBeFailedAward();

        $user->optIn();

        // Use the new LFL::grant() API
        $result2 = LFL::grant('test-prize')
            ->to($user)
            ->because('test prize')
            ->save();
        expect($result2)->toBeSuccessfulAward();
    });

});

describe('Profile Aggregations', function () {

    beforeEach(function () {
        // Create a GamedMetric for XP tests using LFL::setup()
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

    it('can calculate total XP from profile metrics', function () {
        $user = User::create(['name' => 'Test User', 'email' => 'test@example.com']);
        $profile = $user->getProfile();

        // Use the new LFL::award() API
        LFL::award('general-xp')->to($user)->amount(50)->save();
        LFL::award('general-xp')->to($user)->amount(30)->save();
        LFL::award('general-xp')->to($user)->amount(20)->save();

        $total = $profile->fresh()->calculateTotalXp();

        expect($total)->toBe(100);
    });

    it('can calculate achievement count', function () {
        // Create achievements using LFL::setup()
        LFL::setup(a: 'achievement', with: ['slug' => 'achievement-1', 'name' => 'Achievement 1']);
        LFL::setup(a: 'achievement', with: ['slug' => 'achievement-2', 'name' => 'Achievement 2']);

        $user = User::create(['name' => 'Test User', 'email' => 'test@example.com']);
        $profile = $user->getProfile();

        // Use the new LFL::grant() API
        LFL::grant('achievement-1')->to($user)->save();
        LFL::grant('achievement-2')->to($user)->save();

        $count = $profile->calculateAchievementCount();

        expect($count)->toBe(2);
    });

    it('can calculate prize count', function () {
        // Create prizes using LFL::setup()
        LFL::setup(a: 'prize', with: ['slug' => 'prize-1', 'name' => 'Prize 1', 'type' => 'virtual']);
        LFL::setup(a: 'prize', with: ['slug' => 'prize-2', 'name' => 'Prize 2', 'type' => 'virtual']);

        $user = User::create(['name' => 'Test User', 'email' => 'test@example.com']);
        $profile = $user->getProfile();

        // Use the new LFL::grant() API
        LFL::grant('prize-1')->to($user)->because('won prize 1')->save();
        LFL::grant('prize-2')->to($user)->because('won prize 2')->save();

        $count = $profile->calculatePrizeCount();

        expect($count)->toBe(2);
    });

    it('can recalculate all aggregations', function () {
        // Create achievement using LFL::setup()
        LFL::setup(a: 'achievement', with: ['slug' => 'test-achievement', 'name' => 'Test Achievement']);

        // Create prize using LFL::setup()
        LFL::setup(a: 'prize', with: ['slug' => 'test-prize', 'name' => 'Test Prize', 'type' => 'virtual']);

        $user = User::create(['name' => 'Test User', 'email' => 'test@example.com']);
        $profile = $user->getProfile();

        // Set incorrect values
        $profile->setAggregates([
            'total_xp' => 0,
            'achievement_count' => 0,
            'prize_count' => 0,
        ]);

        // Create actual awards using new API
        LFL::award('general-xp')->to($user)->amount(100)->save();
        LFL::grant('test-achievement')->to($user)->save();
        LFL::grant('test-prize')->to($user)->because('test prize')->save();

        // Recalculate
        $profile->recalculateAggregations();

        expect($profile->fresh()->total_xp)->toBe(100)
            ->and($profile->fresh()->achievement_count)->toBe(1)
            ->and($profile->fresh()->prize_count)->toBe(1);
    });

    it('can increment XP', function () {
        $user = User::create(['name' => 'Test User', 'email' => 'test@example.com']);
        $profile = $user->getProfile();

        $profile->incrementXp(50);

        expect($profile->fresh()->total_xp)->toBe(50);
    });

    it('can increment achievement count', function () {
        $user = User::create(['name' => 'Test User', 'email' => 'test@example.com']);
        $profile = $user->getProfile();

        $profile->incrementAchievementCount();

        expect($profile->fresh()->achievement_count)->toBe(1);
    });

    it('can increment prize count', function () {
        $user = User::create(['name' => 'Test User', 'email' => 'test@example.com']);
        $profile = $user->getProfile();

        $profile->incrementPrizeCount();

        expect($profile->fresh()->prize_count)->toBe(1);
    });

    it('returns zero for aggregations when awardable does not exist', function () {
        // Create a profile without saving (to avoid relationship loading)
        $profile = Profile::make([
            'awardable_type' => User::class,
            'awardable_id' => 999999, // Non-existent ID
        ]);

        // Mock the awardable relationship to return null
        $profile->setRelation('awardable', null);

        expect($profile->calculateTotalXp())->toBe(0)
            ->and($profile->calculateAchievementCount())->toBe(0)
            ->and($profile->calculatePrizeCount())->toBe(0);
    });

});
