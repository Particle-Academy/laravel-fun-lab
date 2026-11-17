<?php

declare(strict_types=1);

namespace LaravelFunLab\Tests\Conformance;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use InvalidArgumentException;
use LaravelFunLab\Events\AchievementUnlocked;
use LaravelFunLab\Events\AwardGranted;
use LaravelFunLab\Events\CatalogMutated;
use LaravelFunLab\Events\MetricLevelReached;
use LaravelFunLab\Events\PrizeAwarded;
use LaravelFunLab\Events\XpAwarded;
use LaravelFunLab\Exceptions\AwardRejectedException;
use LaravelFunLab\Facades\LFL;
use LaravelFunLab\Http\Resources\AchievementResource;
use LaravelFunLab\Http\Resources\AwardGrantResource;
use LaravelFunLab\Http\Resources\AwardResource;
use LaravelFunLab\Http\Resources\GamedMetricResource;
use LaravelFunLab\Http\Resources\LeaderboardEntryResource;
use LaravelFunLab\Http\Resources\MetricLevelResource;
use LaravelFunLab\Http\Resources\PrizeResource;
use LaravelFunLab\Http\Resources\ProfileResource;
use LaravelFunLab\Models\Achievement;
use LaravelFunLab\Models\AchievementGrant;
use LaravelFunLab\Models\GamedMetric;
use LaravelFunLab\Models\MetricLevel;
use LaravelFunLab\Models\MetricLevelGroup;
use LaravelFunLab\Models\Prize;
use LaravelFunLab\Models\PrizeGrant;
use LaravelFunLab\Models\Profile;
use LaravelFunLab\Models\ProfileMetric;
use LaravelFunLab\Services\MetricLevelGroupService;
use LaravelFunLab\Services\MetricLevelService;
use LaravelFunLab\Tests\Fixtures\AuthUser;
use LaravelFunLab\Tests\Fixtures\User;

/**
 * Runs one `fun-lab/*` conformance scenario through the REAL engine.
 *
 * This is the reference adapter: the goldens in fancy-conformance are what this
 * returns, and the Node and Python twins each carry their own adapter that must
 * return the identical document. It drives only public API (the LFL facade, the
 * Awardable trait, the builders and the HTTP resources), so a golden is a claim
 * about what a consumer sees, never about internals.
 *
 * The scenario names its subjects (`"ada": "user"`) instead of using database
 * ids; awardable ids are assigned 1, 2, 3 in the order the subjects are listed,
 * and awardable types are reported by those names (`user`, `team`), never by a
 * PHP class name, because a twin has no PHP class to name.
 *
 * Call it inside a fresh database; it sets and resets the test clock itself.
 */
final class ScenarioRunner
{
    /** Scenario type name to fixture class. Both share the `users` table. */
    private const TYPES = ['user' => User::class, 'team' => AuthUser::class];

    /** @var array<string, Model> */
    private array $subjects = [];

    /** @var list<array<string, mixed>> */
    private array $events = [];

    /** @var list<array{event: string, payload: array<string, mixed>}> */
    private array $broadcasts = [];

    /**
     * With `$broadcasts`, each step reports the broadcastWith() payload of every
     * broadcastable event it emitted (fun-lab/events) instead of the contract
     * fields (fun-lab/progression and the rest).
     *
     * @param  array<string, mixed>  $input
     * @return array{steps: list<array<string, mixed>>}
     */
    public function run(array $input, bool $broadcasts = false): array
    {
        Carbon::setTestNow(Carbon::parse((string) $input['clock']));

        try {
            $config = (array) ($input['config'] ?? []);
            config([
                'lfl.defaults.max_points_per_action' => (int) ($config['max_points_per_action'] ?? 10000),
                'lfl.events.log_to_database' => (bool) ($config['log_to_database'] ?? true),
                'lfl.events.dispatch' => (bool) ($config['dispatch'] ?? true),
            ]);

            $this->listen();

            $id = 0;
            foreach ((array) ($input['subjects'] ?? []) as $name => $type) {
                $class = self::TYPES[$type] ?? throw new InvalidArgumentException("unknown subject type {$type}");
                $id++;
                $subject = new $class;
                $subject->forceFill(['id' => $id, 'name' => (string) $name, 'email' => $name.'@example.com']);
                $subject->save();
                $this->subjects[(string) $name] = $subject;
            }

            $steps = [];
            foreach ((array) $input['steps'] as $step) {
                $this->events = [];
                $this->broadcasts = [];
                $result = $this->step($step);
                $steps[] = $broadcasts
                    ? ['result' => $result, 'broadcasts' => $this->broadcasts]
                    : ['result' => $result, 'events' => $this->events];
            }

            return ['steps' => $steps];
        } finally {
            Carbon::setTestNow();
        }
    }

    /** @param array<string, mixed> $s */
    private function step(array $s): mixed
    {
        return match ($s['op']) {
            'clock' => $this->clock((string) $s['at']),
            'setup' => $this->guard(function () use ($s) {
                $with = (array) ($s['with'] ?? []);
                // `for` names a scenario subject type; the engine stores a class.
                if (isset($with['for']) && isset(self::TYPES[$with['for']])) {
                    $with['for'] = self::TYPES[$with['for']];
                }

                return ['id' => LFL::setup(a: (string) $s['a'], with: $with)->getKey()];
            }),
            'attach' => $this->attach($s),
            'award' => $this->guard(function () use ($s) {
                $builder = LFL::award((string) $s['metric'])->to($this->subject((string) $s['to']))->amount((int) $s['amount']);
                isset($s['reason']) && $builder->because((string) $s['reason']);
                isset($s['source']) && $builder->from((string) $s['source']);
                isset($s['meta']) && $builder->withMeta((array) $s['meta']);
                $pm = $builder->save();

                return ['total_xp' => (int) $pm->total_xp, 'current_level' => (int) $pm->current_level];
            }),
            'grant' => $this->guard(function () use ($s) {
                $builder = LFL::grant((string) $s['slug'])->to($this->subject((string) $s['to']));
                isset($s['reason']) && $builder->because((string) $s['reason']);
                isset($s['source']) && $builder->from((string) $s['source']);
                isset($s['meta']) && $builder->withMeta((array) $s['meta']);
                $result = $builder->save();

                return ['success' => $result->succeeded(), 'message' => $result->message];
            }),
            'optOut' => $this->subject((string) $s['who'])->optOut(),
            'optIn' => $this->subject((string) $s['who'])->optIn(),
            'redeem' => $this->guard(function () use ($s) {
                $grant = PrizeGrant::findOrFail((int) $s['grant']);
                match ($s['to']) {
                    'claimed' => $grant->claim(),
                    'fulfilled' => $grant->fulfill(),
                };

                return $this->grantResource($grant->fresh());
            }),
            'hasLevel' => $this->guard(fn () => LFL::hasLevel($this->subject((string) $s['who']), (int) $s['level'],
                metric: $s['metric'] ?? null, group: $s['group'] ?? null)),
            'levelInfo' => $this->levelInfo($s),
            'leaderboard' => $this->guard(fn () => $this->leaderboard($s)),
            'profile' => $this->profile((string) $s['who']),
            'catalog' => $this->catalog(),
            'analytics' => $this->guard(fn () => $this->analytics($s)),
            default => throw new InvalidArgumentException("unknown op {$s['op']}"),
        };
    }

    private function clock(string $at): null
    {
        Carbon::setTestNow(Carbon::parse($at));

        return null;
    }

    /** An operation that may refuse: its refusal is part of the contract. */
    private function guard(callable $fn): mixed
    {
        try {
            return $fn();
        } catch (AwardRejectedException $e) {
            return ['error' => 'rejected', 'kind' => $e->kind, 'message' => $e->getMessage()];
        } catch (InvalidArgumentException $e) {
            return ['error' => 'invalid', 'message' => $e->getMessage()];
        }
    }

    private function subject(string $name): Model
    {
        return $this->subjects[$name] ?? throw new InvalidArgumentException("unknown subject {$name}");
    }

    /** @param array<string, mixed> $s */
    private function attach(array $s): mixed
    {
        return $this->guard(function () use ($s) {
            $achievement = Achievement::where('slug', (string) $s['achievement'])->first()
                ?? throw new InvalidArgumentException("Achievement '{$s['achievement']}' not found");

            if (isset($s['metricLevel'])) {
                $metric = GamedMetric::findBySlug((string) $s['metricLevel']['metric'])
                    ?? throw new InvalidArgumentException("GamedMetric '{$s['metricLevel']['metric']}' not found");
                $level = MetricLevel::forMetric($metric->id)->where('level', (int) $s['metricLevel']['level'])->first()
                    ?? throw new InvalidArgumentException('Metric level not found');
            } else {
                $group = MetricLevelGroup::findBySlug((string) $s['groupLevel']['group'])
                    ?? throw new InvalidArgumentException("MetricLevelGroup '{$s['groupLevel']['group']}' not found");
                $level = $group->levels()->where('level', (int) $s['groupLevel']['level'])->first()
                    ?? throw new InvalidArgumentException('Group level not found');
            }
            $level->achievements()->syncWithoutDetaching([$achievement->id]);

            return true;
        });
    }

    /** @param array<string, mixed> $s */
    private function levelInfo(array $s): array
    {
        $who = $this->subject((string) $s['who']);
        if (isset($s['group'])) {
            return app(MetricLevelGroupService::class)->getLevelInfo($who, (string) $s['group']);
        }
        $service = app(MetricLevelService::class);
        $metric = (string) $s['metric'];
        $profile = Profile::where('awardable_type', get_class($who))->where('awardable_id', $who->getKey())->first();
        $pm = $profile === null ? null : ProfileMetric::where('profile_id', $profile->id)
            ->where('gamed_metric_id', GamedMetric::findBySlug($metric)?->id)->first();

        return [
            'current_level' => $service->getCurrentLevel($who, $metric),
            'total_xp' => (int) ($pm?->total_xp ?? 0),
            'next_level_threshold' => $service->getNextLevelThreshold($who, $metric),
            'progress_percentage' => $service->getProgressPercentage($who, $metric),
        ];
    }

    /** @param array<string, mixed> $s */
    private function leaderboard(array $s): array
    {
        $board = LFL::leaderboard();
        isset($s['for']) && $board->for(self::TYPES[$s['for']]);
        isset($s['by']) && $board->by((string) $s['by']);
        array_key_exists('period', $s) && $board->period($s['period']);
        isset($s['metric']) && $board->metric((string) $s['metric']);
        isset($s['excludeOptedOut']) && $board->excludeOptedOut((bool) $s['excludeOptedOut']);

        if (isset($s['page'])) {
            $page = $board->perPage((int) ($s['perPage'] ?? 15))->page((int) $s['page'])->paginate();

            return ['total' => $page->total(), 'entries' => array_map(fn ($p) => $this->entry($p), $page->items())];
        }
        $rows = isset($s['take']) ? $board->take((int) $s['take']) : $board->get();

        return ['entries' => $rows->map(fn ($p) => $this->entry($p))->all()];
    }

    private function entry(Profile $p): array
    {
        return $this->names((new LeaderboardEntryResource($p))->resolve(new Request));
    }

    private function profile(string $who): array
    {
        $subject = $this->subject($who);
        $profile = Profile::where('awardable_type', get_class($subject))->where('awardable_id', $subject->getKey())->first();
        if ($profile === null) {
            return ['profile' => null];
        }

        return [
            'profile' => $this->names((new ProfileResource($profile))->resolve(new Request)),
            'metrics' => ProfileMetric::where('profile_id', $profile->id)->orderBy('id')->get()
                ->map(fn ($m) => (new AwardResource($m))->resolve(new Request))->all(),
            'achievements' => AchievementGrant::where('profile_id', $profile->id)->orderBy('id')->get()
                ->map(fn ($g) => $this->grantResource($g))->all(),
            'prizes' => PrizeGrant::where('profile_id', $profile->id)->orderBy('id')->get()
                ->map(fn ($g) => $this->grantResource($g))->all(),
            // A list, not a map: PHP encodes an empty map as [], which no other
            // runtime would produce for "no groups".
            'groups' => DB::table((new MetricLevelGroup)->getTable())->orderBy('id')->get()
                ->map(fn ($g) => ['group' => $g->slug] + app(MetricLevelGroupService::class)->getLevelInfo($subject, (string) $g->slug))->all(),
        ];
    }

    private function grantResource(Model $grant): array
    {
        $out = (new AwardGrantResource($grant))->resolve(new Request);
        if (isset($out['status']) && $out['status'] instanceof \BackedEnum) {
            $out['status'] = $out['status']->value;
        }

        return $out;
    }

    private function catalog(): array
    {
        return [
            'metrics' => GamedMetric::orderBy('id')->get()->map(fn ($m) => (new GamedMetricResource($m))->resolve(new Request))->all(),
            'levels' => MetricLevel::orderBy('id')->get()->map(fn ($m) => (new MetricLevelResource($m))->resolve(new Request))->all(),
            'achievements' => Achievement::orderBy('id')->get()->map(fn ($a) => $this->names((new AchievementResource($a))->resolve(new Request)))->all(),
            'prizes' => Prize::orderBy('id')->get()->map(fn ($p) => (new PrizeResource($p))->resolve(new Request))->all(),
        ];
    }

    /** @param array<string, mixed> $s */
    private function analytics(array $s): mixed
    {
        $q = LFL::analytics();
        isset($s['byType']) && $q->byType((string) $s['byType']);
        isset($s['forType']) && $q->forAwardableType(self::TYPES[$s['forType']]);
        isset($s['forSubject']) && $q->forAwardable($this->subject((string) $s['forSubject']));
        isset($s['source']) && $q->fromSource((string) $s['source']);
        isset($s['achievement']) && $q->forAchievement((string) $s['achievement']);
        array_key_exists('period', $s) && $q->period($s['period']);
        isset($s['since']) && $q->since((string) $s['since']);
        isset($s['until']) && $q->until((string) $s['until']);

        return match ($s['agg']) {
            'count' => $q->count(),
            'total' => $q->total(),
            'average' => $q->average(),
            'min' => $q->min(),
            'max' => $q->max(),
            'activeUsers' => $q->activeUsers(),
            'completionRate' => $q->achievementCompletionRate(),
            'timeSeries' => $q->timeSeries((string) ($s['interval'] ?? 'day')),
            // Keyed results as lists sorted by key, for the same reason as `groups`.
            'byAwardType' => $this->keyed($q->byAwardType()),
            'byAwardableType' => $this->keyed($this->keyNames($q->byAwardableType())),
            'bySource' => $this->keyed($q->bySource()),
            'export' => array_map(fn (array $row) => $this->names($row), $q->export(isset($s['limit']) ? (int) $s['limit'] : null)),
        };
    }

    /** @param array<string, array<string, mixed>> $map */
    private function keyed(array $map): array
    {
        ksort($map, SORT_STRING);
        $out = [];
        foreach ($map as $key => $value) {
            $out[] = ['key' => (string) $key] + $value;
        }

        return $out;
    }

    /** Report awardable types by scenario name, wherever they appear. */
    private function names(array $row): array
    {
        foreach (['awardable_type', 'recipient_type'] as $key) {
            if (isset($row[$key]) && is_string($row[$key])) {
                $row[$key] = $this->typeName($row[$key]);
            }
        }
        if (isset($row['context']) && is_array($row['context'])) {
            $row['context'] = $this->names($row['context']);
        }

        return $row;
    }

    private function keyNames(array $byType): array
    {
        $out = [];
        foreach ($byType as $type => $value) {
            $out[$this->typeName((string) $type)] = $value;
        }

        return $out;
    }

    private function typeName(string $class): string
    {
        $name = array_search($class, self::TYPES, true);

        return $name === false ? $class : $name;
    }

    /** Capture the engine's events, in order, by stable name and their contract fields. */
    private function listen(): void
    {
        $push = function (string $name, array $fields): void {
            $this->events[] = ['event' => $name] + $fields;
        };

        // The wire payloads, exactly as Echo receives them, for fun-lab/events.
        foreach ([XpAwarded::class, MetricLevelReached::class, AchievementUnlocked::class, PrizeAwarded::class] as $class) {
            Event::listen($class, function (object $e): void {
                $this->broadcasts[] = ['event' => $e->broadcastAs(), 'payload' => $e->broadcastWith()];
            });
        }

        Event::listen(XpAwarded::class, fn (XpAwarded $e) => $push('xp.awarded', [
            'metric' => $e->gamedMetric->slug, 'amount' => $e->amount, 'total_xp' => (int) $e->profileMetric->total_xp,
        ]));
        Event::listen(MetricLevelReached::class, fn (MetricLevelReached $e) => $push('metric.level-reached', [
            'metric' => $e->gamedMetric->slug, 'level' => (int) $e->level->level,
            'achievements' => array_map(fn ($a) => $a->slug, $e->achievements), 'total_xp' => $e->totalXp,
        ]));
        Event::listen(AchievementUnlocked::class, fn (AchievementUnlocked $e) => $push('achievement.unlocked', [
            'achievement' => $e->achievement->slug, 'source' => $e->source,
        ]));
        Event::listen(PrizeAwarded::class, fn (PrizeAwarded $e) => $push('prize.awarded', [
            'prize' => $e->award->prize->slug, 'source' => $e->source,
        ]));
        Event::listen(AwardGranted::class, fn (AwardGranted $e) => $push('award.granted', [
            'type' => $e->type instanceof \BackedEnum ? $e->type->value : $e->type,
        ]));
        Event::listen(CatalogMutated::class, fn (CatalogMutated $e) => $push('catalog.mutated', [
            'entity' => $e->entityType, 'created' => $e->wasCreated,
        ]));
    }
}
