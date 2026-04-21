<?php

declare(strict_types=1);

use LaravelFunLab\Facades\LFL;

beforeEach(function () {
    config(['lfl.api.enabled' => true]);
    config(['lfl.api.writes' => true]);
    config(['lfl.api.auth.middleware' => null]);
});

describe('Catalog: GamedMetrics', function () {
    it('lists GamedMetrics', function () {
        LFL::setup('gamed-metric', ['slug' => 'combat-xp', 'name' => 'Combat XP', 'active' => true]);
        LFL::setup('gamed-metric', ['slug' => 'social-xp', 'name' => 'Social XP', 'active' => true]);

        $response = $this->getJson('/api/lfl/gamed-metrics');

        $response->assertSuccessful();
        expect($response->json('data'))->toHaveCount(2);
    });

    it('creates a GamedMetric via POST', function () {
        $response = $this->postJson('/api/lfl/gamed-metrics', [
            'slug' => 'crafting-xp',
            'name' => 'Crafting XP',
        ]);

        $response->assertSuccessful()
            ->assertJson(['data' => ['slug' => 'crafting-xp', 'name' => 'Crafting XP']]);
    });
});

describe('Catalog: MetricLevels', function () {
    it('lists levels for a GamedMetric', function () {
        LFL::setup('gamed-metric', ['slug' => 'combat-xp', 'name' => 'Combat XP']);
        LFL::setup('metric-level', ['metric' => 'combat-xp', 'level' => 1, 'xp' => 0, 'name' => 'Novice']);
        LFL::setup('metric-level', ['metric' => 'combat-xp', 'level' => 2, 'xp' => 100, 'name' => 'Apprentice']);

        $response = $this->getJson('/api/lfl/metric-levels/combat-xp');

        $response->assertSuccessful();
        expect($response->json('data'))->toHaveCount(2);
    });

    it('returns 404 for unknown metric', function () {
        $this->getJson('/api/lfl/metric-levels/does-not-exist')
            ->assertNotFound();
    });

    it('creates a MetricLevel via POST', function () {
        LFL::setup('gamed-metric', ['slug' => 'combat-xp', 'name' => 'Combat XP']);

        $response = $this->postJson('/api/lfl/metric-levels', [
            'metric' => 'combat-xp',
            'level' => 3,
            'xp' => 500,
            'name' => 'Warrior',
        ]);

        $response->assertSuccessful()
            ->assertJson(['data' => ['level' => 3, 'xp_threshold' => 500, 'name' => 'Warrior']]);
    });
});

describe('Catalog: Prizes', function () {
    it('lists active prizes', function () {
        LFL::setup('prize', ['slug' => 'premium', 'name' => 'Premium Access']);

        $response = $this->getJson('/api/lfl/prizes');

        $response->assertSuccessful();
        expect($response->json('data'))->toHaveCount(1);
        expect($response->json('data.0.slug'))->toBe('premium');
    });

    it('creates a Prize via POST', function () {
        $response = $this->postJson('/api/lfl/prizes', [
            'slug' => 'gem',
            'name' => 'Premium Gem',
            'cost' => 100,
        ]);

        $response->assertSuccessful()
            ->assertJson(['data' => ['slug' => 'gem', 'name' => 'Premium Gem']]);
    });
});

describe('Catalog: Achievements (setup via POST)', function () {
    it('creates an Achievement via POST', function () {
        $response = $this->postJson('/api/lfl/achievements', [
            'slug' => 'first-login',
            'name' => 'First Login',
            'description' => 'Welcome!',
        ]);

        $response->assertSuccessful()
            ->assertJson(['data' => ['slug' => 'first-login', 'name' => 'First Login']]);
    });
});
