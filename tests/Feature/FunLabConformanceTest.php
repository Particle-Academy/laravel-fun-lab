<?php

declare(strict_types=1);

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use LaravelFunLab\Tests\Conformance\ScenarioRunner;
use ParticleAcademy\Conformance\Conformance;

/*
|--------------------------------------------------------------------------
| The fun-lab/* tables, run against THIS engine
|--------------------------------------------------------------------------
|
| @particle-academy/fancy-fun-lab and fancy-fun-lab run the identical rows from
| the identical files. The goldens were exported from this engine, so a red
| here means the engine moved under its own published contract: either the
| change is wrong, or it is a contract change, and then the fixtures are
| re-exported deliberately and the twins follow.
|
| Loaded from the INSTALLED package, never a path to a sibling checkout.
| Each case runs in a transaction that is rolled back, so every scenario starts
| from an empty database with ids from 1, as its golden did.
|
*/

/** Moved deliberately, never automatically. A pin that follows disk asserts nothing. */
const FUN_LAB_SUITE_VERSION = '0.50.0';

const FUN_LAB_SUITES = [
    'fun-lab/strings' => 21,
    'fun-lab/progression' => 17,
    'fun-lab/grants' => 10,
    'fun-lab/leaderboard' => 11,
    'fun-lab/analytics' => 8,
    'fun-lab/events' => 9,
];

function runFunLabCase(array $case): mixed
{
    return match ($case['fn']) {
        'slug' => Str::slug((string) $case['input']['value']),
        'headline' => Str::headline((string) $case['input']['value']),
        'scenario', 'broadcasts' => (function () use ($case) {
            DB::beginTransaction();
            try {
                return (new ScenarioRunner)->run($case['input'], broadcasts: $case['fn'] === 'broadcasts');
            } finally {
                DB::rollBack();
            }
        })(),
        default => throw new RuntimeException("case {$case['id']} calls unimplemented fn {$case['fn']}"),
    };
}

it('pins the fixture version and finds every row', function () {
    // The vacuity guard: a suite that resolves, returns nothing and reports
    // "0 failed" reads exactly like full coverage.
    expect(Conformance::version())->toBe(FUN_LAB_SUITE_VERSION);
    foreach (FUN_LAB_SUITES as $suite => $rows) {
        expect(count(Conformance::cases($suite)))->toBeGreaterThanOrEqual($rows);
    }
});

it('agrees with the fun-lab table', function (string $suite) {
    $summary = Conformance::runTable($suite, 'runFunLabCase'(...));

    // Printed unconditionally, pass or fail.
    fwrite(STDERR, "\n".Conformance::formatSummary($summary)."\n");

    expect($summary['ok'])->toBeTrue(Conformance::formatSummary($summary));
})->with(array_keys(FUN_LAB_SUITES));
