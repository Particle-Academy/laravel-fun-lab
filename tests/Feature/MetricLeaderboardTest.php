<?php

declare(strict_types=1);

use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use LaravelFunLab\Facades\LFL;
use LaravelFunLab\Models\GamedMetric;
use LaravelFunLab\Models\ProfileMetric;
use LaravelFunLab\Tests\Fixtures\User;
use Symfony\Component\Yaml\Yaml;

/*
|--------------------------------------------------------------------------
| Per-metric leaderboards
|--------------------------------------------------------------------------
|
| A GamedMetric is an "XP type". These pin that a leaderboard can rank on ONE
| metric's XP instead of the profile-wide total, over the same periods, with
| the same opt-out exclusion and paging, in a bounded number of queries.
|
*/

beforeEach(function () {
    LFL::setup(a: 'gamed-metric', with: ['slug' => 'combat', 'name' => 'Combat', 'active' => true]);
    LFL::setup(a: 'gamed-metric', with: ['slug' => 'crafting', 'name' => 'Crafting', 'active' => true]);
});

function mlbUser(string $name): User
{
    return User::create(['name' => $name, 'email' => strtolower($name).'@example.com']);
}

/**
 * A: combat 50, crafting 500 (profile total 550)
 * B: combat 200            (profile total 200)
 * C: crafting 300 only     (profile total 300)
 *
 * @return array{0: User, 1: User, 2: User}
 */
function mlbSeed(): array
{
    $a = mlbUser('Ada');
    $b = mlbUser('Bo');
    $c = mlbUser('Cy');

    LFL::award('combat')->to($a)->amount(50)->save();
    LFL::award('crafting')->to($a)->amount(500)->save();
    LFL::award('combat')->to($b)->amount(200)->save();
    LFL::award('crafting')->to($c)->amount(300)->save();

    return [$a, $b, $c];
}

describe('LeaderboardBuilder::metric()', function () {

    it('ranks on the one metric, not the profile-wide total', function () {
        [$a, $b] = mlbSeed();

        $board = LFL::leaderboard()->for(User::class)->metric('combat')->get();

        expect($board->pluck('awardable_id')->all())->toBe([$b->id, $a->id])
            ->and($board->pluck('rank')->all())->toBe([1, 2])
            ->and((int) $board->first()->metric_xp)->toBe(200)
            ->and((int) $board->last()->metric_xp)->toBe(50);
    });

    it('leaves profiles with no XP in that metric off the board', function () {
        [, , $c] = mlbSeed();

        $ids = LFL::leaderboard()->for(User::class)->metric('combat')->get()->pluck('awardable_id')->all();

        expect($ids)->not->toContain($c->id)->and($ids)->toHaveCount(2);
    });

    it('is unchanged without a metric: profile-wide total', function () {
        [$a, $b, $c] = mlbSeed();

        $ids = LFL::leaderboard()->for(User::class)->get()->pluck('awardable_id')->all();

        expect($ids)->toBe([$a->id, $c->id, $b->id]);
    });

    it('accepts a GamedMetric model as well as a slug', function () {
        [$a, $b] = mlbSeed();

        $board = LFL::leaderboard()->for(User::class)->metric(GamedMetric::findBySlug('crafting'))->get();

        expect($board->first()->awardable_id)->toBe($a->id);
    });

    it('throws for an unknown metric rather than returning an empty board', function () {
        LFL::leaderboard()->metric('no-such-metric')->get();
    })->throws(InvalidArgumentException::class, "GamedMetric 'no-such-metric' not found.");

    it('excludes opted-out profiles by default', function () {
        [$a, $b] = mlbSeed();
        $b->getProfile()->optOut();

        $ids = LFL::leaderboard()->for(User::class)->metric('combat')->get()->pluck('awardable_id')->all();

        expect($ids)->toBe([$a->id]);
    });

    it('includes opted-out profiles when asked', function () {
        [$a, $b] = mlbSeed();
        $b->getProfile()->optOut();

        $ids = LFL::leaderboard()->for(User::class)->metric('combat')->excludeOptedOut(false)->get()->pluck('awardable_id')->all();

        expect($ids)->toBe([$b->id, $a->id]);
    });

    it('limits a period board to that metric', function () {
        [$a, $b, $c] = mlbSeed();

        // A's combat row last moved a month ago; everything else this week.
        $combat = GamedMetric::findBySlug('combat');
        ProfileMetric::where('profile_id', $a->getProfile()->id)
            ->where('gamed_metric_id', $combat->id)
            ->update(['updated_at' => Carbon::now()->subMonths(2)]);

        $board = LFL::leaderboard()->for(User::class)->metric('combat')->period('weekly')->get();

        // Only B. A's crafting XP this week must not lift A onto the combat board.
        expect($board->pluck('awardable_id')->all())->toBe([$b->id])
            ->and((int) $board->first()->period_xp)->toBe(200);
    });

    it('a period board without a metric still sums every metric', function () {
        [$a, $b, $c] = mlbSeed();

        $board = LFL::leaderboard()->for(User::class)->period('weekly')->get();

        expect($board->pluck('awardable_id')->all())->toBe([$a->id, $c->id, $b->id])
            ->and((int) $board->first()->period_xp)->toBe(550);
    });

    it('sorts participants by achievements when asked, scoped to the metric', function () {
        [$a, $b, $c] = mlbSeed();
        $b->getProfile()->setAggregates(['achievement_count' => 1]);
        $a->getProfile()->setAggregates(['achievement_count' => 3]);
        $c->getProfile()->setAggregates(['achievement_count' => 9]);

        $ids = LFL::leaderboard()->for(User::class)->metric('combat')->by('achievements')->get()->pluck('awardable_id')->all();

        expect($ids)->toBe([$a->id, $b->id]);
    });

    it('pages with stable ranks and a deterministic tie order', function () {
        $users = collect(range(1, 5))->map(fn ($i) => mlbUser("Tie{$i}"));
        // Two pairs of ties: 100, 100, 50, 50, 10.
        foreach ([100, 100, 50, 50, 10] as $i => $xp) {
            LFL::award('combat')->to($users[$i])->amount($xp)->save();
        }

        $all = [];
        foreach ([1, 2, 3] as $page) {
            $p = LFL::leaderboard()->for(User::class)->metric('combat')->perPage(2)->page($page)->paginate();
            foreach ($p->items() as $row) {
                $all[] = [$row->rank, $row->awardable_id];
            }
            expect($p->total())->toBe(5);
        }

        expect(array_column($all, 0))->toBe([1, 2, 3, 4, 5])
            ->and(array_column($all, 1))->toBe($users->pluck('id')->all());
    });

    it('runs in a bounded number of queries however many profiles there are', function () {
        $count = function (int $profiles): int {
            for ($i = 0; $i < $profiles; $i++) {
                LFL::award('combat')->to(mlbUser('Q'.uniqid()))->amount(10 + $i)->save();
            }
            DB::flushQueryLog();
            DB::enableQueryLog();
            LFL::leaderboard()->for(User::class)->metric('combat')->perPage(50)->paginate();
            $n = count(DB::getQueryLog());
            DB::disableQueryLog();

            return $n;
        };

        $few = $count(2);
        $many = $count(12);

        expect($many)->toBe($few)->and($few)->toBeLessThanOrEqual(3);
    });

    it('has an index that serves a per-metric sort', function () {
        $table = config('lfl.table_prefix', 'lfl_').'profile_metrics';
        $columns = collect(Schema::getIndexes($table))->pluck('columns')->all();

        expect($columns)->toContain(['gamed_metric_id', 'total_xp'])
            ->and($columns)->toContain(['gamed_metric_id', 'updated_at']);
    });
});

describe('GET /leaderboards/{type}?metric=', function () {

    beforeEach(function () {
        config(['lfl.api.enabled' => true, 'lfl.api.auth.middleware' => null]);
    });

    it('ranks on the metric and reports its XP and level per entry', function () {
        [$a, $b] = mlbSeed();

        $response = $this->getJson('/api/lfl/leaderboards/'.urlencode(User::class).'?metric=combat');

        $response->assertSuccessful()
            ->assertJsonCount(2, 'data')
            ->assertJsonPath('data.0.awardable_id', $b->id)
            ->assertJsonPath('data.0.rank', 1)
            ->assertJsonPath('data.0.metric.slug', 'combat')
            ->assertJsonPath('data.0.metric.total_xp', 200)
            ->assertJsonPath('data.0.metric.current_level', 1)
            ->assertJsonPath('data.1.awardable_id', $a->id)
            ->assertJsonPath('data.1.metric.total_xp', 50);
    });

    it('omits the metric block when no metric is asked for', function () {
        mlbSeed();

        $response = $this->getJson('/api/lfl/leaderboards/'.urlencode(User::class));

        $response->assertSuccessful()->assertJsonMissingPath('data.0.metric');
    });

    it('reports period_xp on a period board', function () {
        [, $b] = mlbSeed();

        $this->getJson('/api/lfl/leaderboards/'.urlencode(User::class).'?metric=combat&period=weekly')
            ->assertSuccessful()
            ->assertJsonPath('data.0.awardable_id', $b->id)
            ->assertJsonPath('data.0.period_xp', 200);
    });

    it('rejects an unknown metric with 422', function () {
        $this->getJson('/api/lfl/leaderboards/'.urlencode(User::class).'?metric=nope')
            ->assertStatus(422)
            ->assertJsonValidationErrors(['metric']);
    });

    it('excludes opted-out profiles', function () {
        [$a, $b] = mlbSeed();
        $b->getProfile()->optOut();

        $this->getJson('/api/lfl/leaderboards/'.urlencode(User::class).'?metric=combat')
            ->assertSuccessful()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.awardable_id', $a->id);
    });

    it('is documented in the OpenAPI spec', function () {
        $spec = Yaml::parseFile(__DIR__.'/../../resources/openapi.yaml');
        $op = $spec['paths']['/leaderboards/{type}']['get'];
        $names = collect($op['parameters'])->pluck('name')->filter()->all();

        expect($names)->toContain('metric')
            ->and($op['responses'])->toHaveKey('422')
            ->and($spec['components']['schemas']['LeaderboardEntry']['properties'])->toHaveKeys(['metric', 'period_xp']);
    });
});
