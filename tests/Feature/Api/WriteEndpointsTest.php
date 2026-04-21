<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Event;
use LaravelFunLab\Events\AchievementUnlocked;
use LaravelFunLab\Events\XpAwarded;
use LaravelFunLab\Facades\LFL;
use LaravelFunLab\Tests\Fixtures\User;

beforeEach(function () {
    config(['lfl.api.enabled' => true]);
    config(['lfl.api.writes' => true]);
    config(['lfl.api.auth.middleware' => null]);

    LFL::setup('gamed-metric', ['slug' => 'general-xp', 'name' => 'General XP']);
});

describe('POST /awards', function () {
    it('awards XP to an awardable', function () {
        Event::fake([XpAwarded::class]);
        $user = User::create(['name' => 'A', 'email' => 'a@x.test']);

        $response = $this->postJson('/api/lfl/awards', [
            'metric_slug' => 'general-xp',
            'awardable_type' => User::class,
            'awardable_id' => $user->id,
            'amount' => 50,
            'reason' => 'test',
        ]);

        $response->assertSuccessful()
            ->assertJson([
                'data' => [
                    'gamed_metric_slug' => 'general-xp',
                    'total_xp' => 50,
                ],
            ]);

        Event::assertDispatched(XpAwarded::class);
    });

    it('rejects unknown awardable type', function () {
        $this->postJson('/api/lfl/awards', [
            'metric_slug' => 'general-xp',
            'awardable_type' => 'App\\NoSuchModel',
            'awardable_id' => 1,
            'amount' => 10,
        ])->assertStatus(422);
    });

    it('rejects missing amount', function () {
        $user = User::create(['name' => 'A', 'email' => 'a@x.test']);

        $this->postJson('/api/lfl/awards', [
            'metric_slug' => 'general-xp',
            'awardable_type' => User::class,
            'awardable_id' => $user->id,
        ])->assertStatus(422);
    });
});

describe('POST /grants', function () {
    it('grants an Achievement', function () {
        Event::fake([AchievementUnlocked::class]);
        $user = User::create(['name' => 'B', 'email' => 'b@x.test']);
        LFL::setup('achievement', ['slug' => 'first-login', 'name' => 'First Login']);

        $response = $this->postJson('/api/lfl/grants', [
            'slug' => 'first-login',
            'awardable_type' => User::class,
            'awardable_id' => $user->id,
        ]);

        $response->assertSuccessful()
            ->assertJson(['data' => ['grant_type' => 'achievement', 'achievement_slug' => 'first-login']]);

        Event::assertDispatched(AchievementUnlocked::class);
    });

    it('grants a Prize', function () {
        $user = User::create(['name' => 'C', 'email' => 'c@x.test']);
        LFL::setup('prize', ['slug' => 'gem', 'name' => 'Gem']);

        $response = $this->postJson('/api/lfl/grants', [
            'slug' => 'gem',
            'awardable_type' => User::class,
            'awardable_id' => $user->id,
        ]);

        $response->assertSuccessful()
            ->assertJson(['data' => ['grant_type' => 'prize', 'prize_slug' => 'gem']]);
    });

    it('returns 422 for unknown slug', function () {
        $user = User::create(['name' => 'D', 'email' => 'd@x.test']);

        $this->postJson('/api/lfl/grants', [
            'slug' => 'does-not-exist',
            'awardable_type' => User::class,
            'awardable_id' => $user->id,
        ])->assertStatus(422);
    });
});

describe('POST /profiles/{type}/{id}/opt-in|opt-out', function () {
    it('opts a profile out then back in', function () {
        $user = User::create(['name' => 'E', 'email' => 'e@x.test']);
        $user->getProfile(); // ensure profile exists

        $type = urlencode(User::class);

        $this->postJson("/api/lfl/profiles/{$type}/{$user->id}/opt-out")
            ->assertSuccessful()
            ->assertJson(['data' => ['is_opted_in' => false]]);

        $this->postJson("/api/lfl/profiles/{$type}/{$user->id}/opt-in")
            ->assertSuccessful()
            ->assertJson(['data' => ['is_opted_in' => true]]);
    });

    it('returns 404 for unknown awardable', function () {
        $type = urlencode(User::class);
        $this->postJson("/api/lfl/profiles/{$type}/9999/opt-in")
            ->assertNotFound();
    });
});
