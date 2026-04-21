<?php

declare(strict_types=1);

use LaravelFunLab\Facades\LFL;
use LaravelFunLab\Models\Achievement;
use LaravelFunLab\Models\GamedMetric;
use LaravelFunLab\Tests\Fixtures\User;

/*
|--------------------------------------------------------------------------
| API Tests
|--------------------------------------------------------------------------
|
| Tests for the REST API endpoints including profiles, leaderboards,
| achievements, and profile metrics. Verifies JSON responses, filtering, and pagination.
|
*/

describe('API Routes', function () {

    beforeEach(function () {
        // Enable API in config for tests
        config(['lfl.api.enabled' => true]);
        config(['lfl.api.prefix' => 'api/lfl']);
        // Disable auth middleware for tests (set to null)
        config(['lfl.api.auth.middleware' => null]);

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

    describe('Profile API', function () {

        it('returns profile data for a user', function () {
            $user = User::create(['name' => 'Test User', 'email' => 'test@example.com']);
            $profile = $user->getProfile();
            $profile->setAggregates([
                'total_xp' => 150,
                'achievement_count' => 3,
                'prize_count' => 2,
            ]);

            $response = $this->getJson('/api/lfl/profiles/'.urlencode(User::class).'/'.$user->id);

            $response->assertSuccessful()
                ->assertJsonStructure([
                    'data' => [
                        'id',
                        'awardable_type',
                        'awardable_id',
                        'is_opted_in',
                        'display_preferences',
                        'visibility_settings',
                        'total_xp',
                        'achievement_count',
                        'prize_count',
                        'last_activity_at',
                        'created_at',
                        'updated_at',
                    ],
                ])
                ->assertJson([
                    'data' => [
                        'awardable_type' => User::class,
                        'awardable_id' => $user->id,
                        'total_xp' => 150,
                        'achievement_count' => 3,
                        'prize_count' => 2,
                    ],
                ]);
        });

        it('returns 404 when profile does not exist', function () {
            $response = $this->getJson('/api/lfl/profiles/'.urlencode(User::class).'/999');

            $response->assertNotFound()
                ->assertJson([
                    'message' => 'Profile not found',
                ]);
        });

    });

    describe('Leaderboard API', function () {

        it('returns leaderboard data for a type', function () {
            $user1 = User::create(['name' => 'User 1', 'email' => 'user1@example.com']);
            $user2 = User::create(['name' => 'User 2', 'email' => 'user2@example.com']);

            $profile1 = $user1->getProfile();
            $profile2 = $user2->getProfile();

            $profile1->setAggregates(['total_xp' => 200]);
            $profile2->setAggregates(['total_xp' => 100]);

            $response = $this->getJson('/api/lfl/leaderboards/'.urlencode(User::class));

            $response->assertSuccessful()
                ->assertJsonStructure([
                    'data' => [
                        '*' => [
                            'rank',
                            'id',
                            'awardable_type',
                            'awardable_id',
                            'total_xp',
                            'achievement_count',
                            'prize_count',
                            'last_activity_at',
                        ],
                    ],
                    'meta' => [
                        'current_page',
                        'from',
                        'last_page',
                        'per_page',
                        'to',
                        'total',
                    ],
                    'links' => [
                        'first',
                        'last',
                        'prev',
                        'next',
                    ],
                ]);

            $data = $response->json('data');
            expect($data)->toHaveCount(2)
                ->and($data[0]['total_xp'])->toBe(200)
                ->and($data[0]['rank'])->toBe(1)
                ->and($data[1]['total_xp'])->toBe(100)
                ->and($data[1]['rank'])->toBe(2);
        });

        it('supports filtering by metric', function () {
            $user1 = User::create(['name' => 'User 1', 'email' => 'user1@example.com']);
            $user2 = User::create(['name' => 'User 2', 'email' => 'user2@example.com']);

            $profile1 = $user1->getProfile();
            $profile2 = $user2->getProfile();

            $profile1->setAggregates(['achievement_count' => 5]);
            $profile2->setAggregates(['achievement_count' => 10]);

            $response = $this->getJson('/api/lfl/leaderboards/'.urlencode(User::class).'?by=achievements');

            $response->assertSuccessful();
            $data = $response->json('data');
            expect($data[0]['achievement_count'])->toBe(10)
                ->and($data[1]['achievement_count'])->toBe(5);
        });

        it('supports pagination', function () {
            // Create multiple users
            for ($i = 1; $i <= 20; $i++) {
                $user = User::create(['name' => "User {$i}", 'email' => "user{$i}@example.com"]);
                $profile = $user->getProfile();
                $profile->setAggregates(['total_xp' => $i * 10]);
            }

            $response = $this->getJson('/api/lfl/leaderboards/'.urlencode(User::class).'?per_page=5&page=1');

            $response->assertSuccessful();
            $data = $response->json('data');
            expect($data)->toHaveCount(5)
                ->and($response->json('meta.per_page'))->toBe(5)
                ->and($response->json('meta.current_page'))->toBe(1);
        });

        it('supports time period filtering', function () {
            $user1 = User::create(['name' => 'User 1', 'email' => 'user1@example.com']);
            $user2 = User::create(['name' => 'User 2', 'email' => 'user2@example.com']);

            $profile1 = $user1->getProfile();
            $profile2 = $user2->getProfile();

            $profile1->setAggregates(['total_xp' => 100]);
            $profile2->setAggregates(['total_xp' => 50]);

            $response = $this->getJson('/api/lfl/leaderboards/'.urlencode(User::class).'?period=weekly');

            $response->assertSuccessful();
            expect($response->json('data'))->toBeArray();
        });

    });

    describe('Achievement API', function () {

        it('returns all active achievements', function () {
            Achievement::create([
                'slug' => 'first-achievement',
                'name' => 'First Achievement',
                'description' => 'Complete your first task',
                'is_active' => true,
                'sort_order' => 1,
            ]);

            Achievement::create([
                'slug' => 'second-achievement',
                'name' => 'Second Achievement',
                'description' => 'Complete 10 tasks',
                'is_active' => true,
                'sort_order' => 2,
            ]);

            Achievement::create([
                'slug' => 'inactive-achievement',
                'name' => 'Inactive Achievement',
                'is_active' => false,
                'sort_order' => 3,
            ]);

            $response = $this->getJson('/api/lfl/achievements');

            $response->assertSuccessful()
                ->assertJsonStructure([
                    'data' => [
                        '*' => [
                            'id',
                            'slug',
                            'name',
                            'description',
                            'icon',
                            'awardable_type',
                            'meta',
                            'is_active',
                            'sort_order',
                            'created_at',
                            'updated_at',
                        ],
                    ],
                ]);

            $data = $response->json('data');
            expect($data)->toHaveCount(2)
                ->and($data[0]['slug'])->toBe('first-achievement')
                ->and($data[1]['slug'])->toBe('second-achievement');
        });

        it('filters by awardable type', function () {
            Achievement::create([
                'slug' => 'user-achievement',
                'name' => 'User Achievement',
                'awardable_type' => User::class,
                'is_active' => true,
            ]);

            Achievement::create([
                'slug' => 'universal-achievement',
                'name' => 'Universal Achievement',
                'awardable_type' => null,
                'is_active' => true,
            ]);

            $response = $this->getJson('/api/lfl/achievements?awardable_type='.urlencode(User::class));

            $response->assertSuccessful();
            $data = $response->json('data');
            expect($data)->toHaveCount(2); // Should include both user-specific and universal
        });

        it('can include inactive achievements', function () {
            Achievement::create([
                'slug' => 'active-achievement',
                'name' => 'Active Achievement',
                'is_active' => true,
            ]);

            Achievement::create([
                'slug' => 'inactive-achievement',
                'name' => 'Inactive Achievement',
                'is_active' => false,
            ]);

            $response = $this->getJson('/api/lfl/achievements?active=false');

            $response->assertSuccessful();
            $data = $response->json('data');
            expect($data)->toHaveCount(1)
                ->and($data[0]['slug'])->toBe('inactive-achievement');
        });

    });

    describe('Profile Metrics API', function () {

        it('returns XP history for a user', function () {
            $user = User::create(['name' => 'Test User', 'email' => 'test@example.com']);
            $user->getProfile();

            // Award XP using LFL::award()
            LFL::award('general-xp')->to($user)->amount(50)->save();
            LFL::award('general-xp')->to($user)->amount(30)->save();

            // Check that profile metrics were created
            $metrics = $user->getProfile()->metrics;

            expect($metrics)->toHaveCount(1)
                ->and($metrics->first()->total_xp)->toBe(80);
        });

        it('returns empty array when user has no XP', function () {
            $user = User::create(['name' => 'Test User', 'email' => 'test@example.com']);
            $profile = $user->getProfile();

            expect($profile->metrics)->toHaveCount(0);
        });

    });

});
