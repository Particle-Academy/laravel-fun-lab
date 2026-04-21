<?php

declare(strict_types=1);

use LaravelFunLab\Tests\Fixtures\User;

// WritesDisabledTestCase is applied folder-wide via tests/Pest.php.

/*
|--------------------------------------------------------------------------
| Writes gate
|--------------------------------------------------------------------------
|
| Asserts that `config('lfl.api.writes') === false` prevents any POST
| endpoint from being registered. GETs still work. Depending on whether
| the path also has a GET route, disabled writes produce either 404
| (no route at all) or 405 (path exists but POST not allowed) — both
| prove the write endpoint is unreachable.
|
*/

function assertWriteDisabled(\Illuminate\Testing\TestResponse $response): void
{
    expect($response->status())->toBeIn([404, 405]);
}

it('does not register any POST endpoints', function () {
    $routes = collect(Illuminate\Support\Facades\Route::getRoutes()->getRoutes())
        ->filter(fn ($r) => str_starts_with($r->uri(), 'api/lfl'))
        ->filter(fn ($r) => in_array('POST', $r->methods(), true));

    expect($routes)->toBeEmpty();
});

it('blocks POST /awards', function () {
    $user = User::create(['name' => 'Gate', 'email' => 'gate@x.test']);

    assertWriteDisabled($this->postJson('/api/lfl/awards', [
        'metric_slug' => 'general-xp',
        'awardable_type' => User::class,
        'awardable_id' => $user->id,
        'amount' => 10,
    ]));
});

it('blocks POST /grants', function () {
    $user = User::create(['name' => 'Gate', 'email' => 'gate@x.test']);

    assertWriteDisabled($this->postJson('/api/lfl/grants', [
        'slug' => 'anything',
        'awardable_type' => User::class,
        'awardable_id' => $user->id,
    ]));
});

it('blocks POST /gamed-metrics', function () {
    assertWriteDisabled($this->postJson('/api/lfl/gamed-metrics', [
        'slug' => 'x',
        'name' => 'X',
    ]));
});

it('blocks POST /achievements', function () {
    assertWriteDisabled($this->postJson('/api/lfl/achievements', [
        'slug' => 'x',
        'name' => 'X',
    ]));
});

it('blocks POST /prizes', function () {
    assertWriteDisabled($this->postJson('/api/lfl/prizes', [
        'slug' => 'x',
        'name' => 'X',
    ]));
});

it('blocks POST /metric-levels', function () {
    assertWriteDisabled($this->postJson('/api/lfl/metric-levels', [
        'metric' => 'x',
        'level' => 1,
        'xp' => 0,
    ]));
});

it('blocks POST /profiles/{type}/{id}/opt-in', function () {
    $user = User::create(['name' => 'Gate', 'email' => 'gate@x.test']);

    assertWriteDisabled(
        $this->postJson('/api/lfl/profiles/'.urlencode(User::class).'/'.$user->id.'/opt-in')
    );
});

it('still serves GET endpoints when writes are disabled', function () {
    $this->getJson('/api/lfl/achievements')->assertSuccessful();
    $this->getJson('/api/lfl/gamed-metrics')->assertSuccessful();
});
