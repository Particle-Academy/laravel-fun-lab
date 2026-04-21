<?php

declare(strict_types=1);

use LaravelFunLab\Facades\LFL;
use LaravelFunLab\Tests\Fixtures\AuthUser as User;

beforeEach(function () {
    config(['lfl.api.enabled' => true]);
    config(['lfl.api.writes' => true]);
    config(['lfl.api.auth.middleware' => null]);

    LFL::setup('gamed-metric', ['slug' => 'general-xp', 'name' => 'General XP']);
});

describe('/me endpoints', function () {
    it('returns 401 when unauthenticated', function () {
        $this->getJson('/api/lfl/me/profile')->assertUnauthorized();
    });

    it('returns the authenticated awardable profile', function () {
        $user = User::create(['name' => 'Me', 'email' => 'me@x.test']);

        $response = $this->actingAs($user)->getJson('/api/lfl/me/profile');

        $response->assertSuccessful()
            ->assertJson([
                'data' => [
                    'awardable_type' => User::class,
                    'awardable_id' => $user->id,
                    'is_opted_in' => true,
                ],
            ]);
    });

    it('lists granted achievements for the authenticated user', function () {
        $user = User::create(['name' => 'Me', 'email' => 'me@x.test']);
        LFL::setup('achievement', ['slug' => 'a1', 'name' => 'One']);
        LFL::grant('a1')->to($user)->save();

        $response = $this->actingAs($user)->getJson('/api/lfl/me/achievements');

        $response->assertSuccessful();
        expect($response->json('data'))->toHaveCount(1);
        expect($response->json('data.0.slug'))->toBe('a1');
    });

    it('lists XP awards for the authenticated user', function () {
        $user = User::create(['name' => 'Me', 'email' => 'me@x.test']);
        LFL::award('general-xp')->to($user)->amount(42)->save();

        $response = $this->actingAs($user)->getJson('/api/lfl/me/awards');

        $response->assertSuccessful();
        expect($response->json('data'))->toHaveCount(1);
        expect($response->json('data.0.total_xp'))->toBe(42);
        expect($response->json('profile.total_xp'))->toBe(42);
    });
});
