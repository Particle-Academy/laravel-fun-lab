<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Event;
use LaravelFunLab\Events\CatalogMutated;
use LaravelFunLab\Exceptions\AwardRejectedException;
use LaravelFunLab\Facades\LFL;
use LaravelFunLab\Models\Profile;
use LaravelFunLab\Pipelines\AwardValidationPipeline;
use LaravelFunLab\Tests\Fixtures\User;

/*
|--------------------------------------------------------------------------
| Security hardening regression tests
|--------------------------------------------------------------------------
|
| One test per finding from the LFL package security audit. Each prevents
| the vulnerability from regressing.
|
*/

beforeEach(function () {
    LFL::setup('gamed-metric', ['slug' => 'general-xp', 'name' => 'General XP']);
    AwardValidationPipeline::flush();
});

afterEach(function () {
    AwardValidationPipeline::flush();
});

describe('C3 — Awardable type allowlist', function () {
    it('rejects a write with a non-allowlisted awardable_type (no autoload)', function () {
        $user = User::create(['name' => 'A', 'email' => 'a@x.test']);

        $response = $this->postJson('/api/lfl/awards', [
            'metric_slug' => 'general-xp',
            'awardable_type' => 'App\\Internal\\SecretModel',
            'awardable_id' => $user->id,
            'amount' => 10,
        ]);

        $response->assertStatus(422);
        expect($response->json('message'))->toContain("'App\\Internal\\SecretModel' is not permitted");
    });

    it('rejects a grant with a non-allowlisted awardable_type', function () {
        $user = User::create(['name' => 'B', 'email' => 'b@x.test']);
        LFL::setup('achievement', ['slug' => 'test-ach', 'name' => 'Test']);

        $this->postJson('/api/lfl/grants', [
            'slug' => 'test-ach',
            'awardable_type' => 'Totally\\Arbitrary\\Class',
            'awardable_id' => $user->id,
        ])->assertStatus(422);
    });

    it('rejects opt-in with a non-allowlisted awardable_type', function () {
        $user = User::create(['name' => 'C', 'email' => 'c@x.test']);
        $type = urlencode('Evil\\Class');

        $this->postJson("/api/lfl/profiles/{$type}/{$user->id}/opt-out")
            ->assertStatus(422);
    });
});

describe('C2 — Authorization hook deny-by-default', function () {
    it('denies writes with 403 when no authorize callable is set and allow_missing is false', function () {
        config(['lfl.authorize.allow_missing' => false]);
        config(['lfl.authorize.award' => null]);

        $user = User::create(['name' => 'D', 'email' => 'd@x.test']);

        $this->postJson('/api/lfl/awards', [
            'metric_slug' => 'general-xp',
            'awardable_type' => User::class,
            'awardable_id' => $user->id,
            'amount' => 10,
        ])->assertStatus(403);
    });

    it('allows writes when the authorize callable returns true', function () {
        config(['lfl.authorize.allow_missing' => false]);
        config(['lfl.authorize.award' => fn ($user, $ctx) => true]);

        $user = User::create(['name' => 'E', 'email' => 'e@x.test']);

        $this->postJson('/api/lfl/awards', [
            'metric_slug' => 'general-xp',
            'awardable_type' => User::class,
            'awardable_id' => $user->id,
            'amount' => 10,
        ])->assertSuccessful();
    });

    it('denies writes when the authorize callable returns false', function () {
        config(['lfl.authorize.allow_missing' => false]);
        config(['lfl.authorize.award' => fn ($user, $ctx) => false]);

        $user = User::create(['name' => 'F', 'email' => 'f@x.test']);

        $this->postJson('/api/lfl/awards', [
            'metric_slug' => 'general-xp',
            'awardable_type' => User::class,
            'awardable_id' => $user->id,
            'amount' => 10,
        ])->assertStatus(403);
    });

    it('passes the recipient + metric_slug + amount to the award authorizer', function () {
        $calls = [];
        config(['lfl.authorize.allow_missing' => false]);
        config(['lfl.authorize.award' => function ($user, $ctx) use (&$calls) {
            $calls[] = [
                'recipient_class' => get_class($ctx['recipient']),
                'metric_slug' => $ctx['metric_slug'],
                'amount' => $ctx['amount'],
            ];

            return true;
        }]);

        $user = User::create(['name' => 'G', 'email' => 'g@x.test']);

        $this->postJson('/api/lfl/awards', [
            'metric_slug' => 'general-xp',
            'awardable_type' => User::class,
            'awardable_id' => $user->id,
            'amount' => 25,
        ])->assertSuccessful();

        expect($calls)->toHaveCount(1);
        expect($calls[0])->toBe([
            'recipient_class' => User::class,
            'metric_slug' => 'general-xp',
            'amount' => 25,
        ]);
    });
});

describe('H1 — XP cap + validation pipeline', function () {
    it('rejects amounts above the configured per-action cap', function () {
        config(['lfl.defaults.max_points_per_action' => 100]);
        $user = User::create(['name' => 'H', 'email' => 'h@x.test']);

        expect(fn () => LFL::award('general-xp')->to($user)->amount(500)->save())
            ->toThrow(AwardRejectedException::class);
    });

    it('invokes registered validation pipeline steps on XP awards', function () {
        $calls = 0;
        AwardValidationPipeline::addStep(function ($awardable, $type, $amount) use (&$calls) {
            $calls++;

            return $amount < 50 ? ['valid' => true] : ['valid' => false, 'message' => 'too much'];
        });

        $user = User::create(['name' => 'I', 'email' => 'i@x.test']);

        LFL::award('general-xp')->to($user)->amount(10)->save();
        expect($calls)->toBe(1);

        expect(fn () => LFL::award('general-xp')->to($user)->amount(100)->save())
            ->toThrow(AwardRejectedException::class);
        expect($calls)->toBe(2);
    });

    it('caps API amount via AwardXpRequest rules', function () {
        config(['lfl.defaults.max_points_per_action' => 200]);
        $user = User::create(['name' => 'J', 'email' => 'j@x.test']);

        $this->postJson('/api/lfl/awards', [
            'metric_slug' => 'general-xp',
            'awardable_type' => User::class,
            'awardable_id' => $user->id,
            'amount' => 999,
        ])->assertStatus(422);
    });
});

describe('H2 — Opt-out on XP', function () {
    it('blocks XP awards to opted-out awardables', function () {
        $user = User::create(['name' => 'K', 'email' => 'k@x.test']);
        $user->getProfile()->optOut();

        expect(fn () => LFL::award('general-xp')->to($user)->amount(10)->save())
            ->toThrow(AwardRejectedException::class);
    });
});

describe('H3 — Catalog audit trail', function () {
    it('dispatches CatalogMutated on LFL::setup', function () {
        Event::fake([CatalogMutated::class]);

        LFL::setup('achievement', ['slug' => 'logged', 'name' => 'Logged']);

        Event::assertDispatched(CatalogMutated::class, fn ($e) => $e->entityType === 'achievement'
            && $e->model->slug === 'logged'
            && $e->wasCreated === true);
    });
});

describe('H4 — Profile visibility', function () {
    it('returns 403 for anonymous reads of non-public profiles', function () {
        $user = User::create(['name' => 'L', 'email' => 'l@x.test']);
        $profile = $user->getProfile();
        $profile->update(['visibility_settings' => ['public' => false]]);

        $this->getJson('/api/lfl/profiles/'.urlencode(User::class).'/'.$user->id)
            ->assertStatus(403);
    });

    it('allows public reads when visibility.public is true or unset', function () {
        $user = User::create(['name' => 'M', 'email' => 'm@x.test']);
        $user->getProfile();

        $this->getJson('/api/lfl/profiles/'.urlencode(User::class).'/'.$user->id)
            ->assertSuccessful();
    });
});

describe('M1 — Mass-assign hardening', function () {
    it('silently drops aggregate fields from Profile::create and ::update', function () {
        $user = User::create(['name' => 'N', 'email' => 'n@x.test']);

        $profile = Profile::create([
            'awardable_type' => User::class,
            'awardable_id' => $user->id,
            'total_xp' => 999,
            'achievement_count' => 7,
            'prize_count' => 3,
        ]);

        expect($profile->total_xp)->toBe(0);
        expect($profile->achievement_count)->toBe(0);
        expect($profile->prize_count)->toBe(0);

        $profile->update(['total_xp' => 500, 'achievement_count' => 99]);

        expect($profile->fresh()->total_xp)->toBe(0);
        expect($profile->fresh()->achievement_count)->toBe(0);
    });

    it('setAggregates allows explicit aggregate writes', function () {
        $user = User::create(['name' => 'O', 'email' => 'o@x.test']);
        $profile = $user->getProfile();

        $profile->setAggregates([
            'total_xp' => 500,
            'achievement_count' => 4,
            'prize_count' => 2,
            'unrelated_key' => 'ignored',
        ]);

        expect($profile->fresh()->total_xp)->toBe(500);
        expect($profile->fresh()->achievement_count)->toBe(4);
        expect($profile->fresh()->prize_count)->toBe(2);
    });
});
