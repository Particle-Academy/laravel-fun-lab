<?php

declare(strict_types=1);

use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use LaravelFunLab\Facades\LFL;
use LaravelFunLab\Tests\Fixtures\User;

/*
|--------------------------------------------------------------------------
| Analytics answers that did not depend on the database you happened to use
|--------------------------------------------------------------------------
|
| Found while exporting the fun-lab conformance fixtures, which every runtime
| must reproduce: three analytics reads whose answer was not a property of the
| data. forAwardable() filtered by type alone, export() left same-instant rows
| in whatever order the database chose, and bySource() wrote its fallback as a
| double-quoted string, which SQLite reads as a literal and Postgres as a
| column name.
|
*/

beforeEach(function () {
    Carbon::setTestNow(Carbon::parse('2026-11-18 12:00:00'));
    LFL::setup(a: 'gamed-metric', with: ['slug' => 'combat']);
    $this->ada = User::create(['name' => 'Ada', 'email' => 'ada@example.com']);
    $this->bo = User::create(['name' => 'Bo', 'email' => 'bo@example.com']);
});

afterEach(fn () => Carbon::setTestNow());

it('forAwardable() counts that awardable only, not every awardable of its type', function () {
    LFL::award('combat')->to($this->ada)->amount(10)->save();
    LFL::award('combat')->to($this->bo)->amount(20)->save();
    LFL::award('combat')->to($this->bo)->amount(30)->save();

    expect(LFL::analytics()->forAwardable($this->ada)->count())->toBe(1)
        ->and(LFL::analytics()->forAwardable($this->bo)->total())->toBe(50.0);
});

it('export() orders rows logged in the same instant newest first, by id', function () {
    LFL::award('combat')->to($this->ada)->amount(1)->save();
    LFL::award('combat')->to($this->ada)->amount(2)->save();
    LFL::award('combat')->to($this->ada)->amount(3)->save();

    $sql = [];
    DB::listen(function ($query) use (&$sql) {
        $sql[] = $query->sql;
    });
    $amounts = array_map(fn (array $row) => (int) $row['amount'], LFL::analytics()->byType('points')->export());

    // SQLite happens to return these newest first anyway, so the order alone
    // proves nothing: the tie-break has to be in the query.
    $exportSql = collect($sql)->last(fn (string $q) => str_contains($q, 'order by'));
    expect($amounts)->toBe([3, 2, 1])
        ->and($exportSql)->toMatch('/order by "occurred_at" desc, "id" desc/');
});

it('bySource() writes its fallback as a string literal, not an identifier', function () {
    $sql = [];
    DB::listen(function ($query) use (&$sql) {
        $sql[] = $query->sql;
    });

    LFL::award('combat')->to($this->ada)->amount(5)->save();
    $bySource = LFL::analytics()->byType('points')->bySource();

    $grouping = collect($sql)->first(fn (string $q) => str_contains($q, 'COALESCE(source'));
    expect($grouping)->not->toBeNull()
        ->and($grouping)->not->toContain('"unknown"')
        ->and($bySource)->toBe(['unknown' => ['count' => 1, 'total' => 5.0]]);
});
