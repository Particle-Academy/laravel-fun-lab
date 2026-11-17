<?php

declare(strict_types=1);

use Illuminate\Support\Str;
use LaravelFunLab\Tests\Conformance\ScenarioRunner;

/*
|--------------------------------------------------------------------------
| Export the fun-lab/* conformance goldens from THIS engine
|--------------------------------------------------------------------------
|
| Not part of the default run (tests/Conformance is not a testsuite in
| phpunit.xml). Run it explicitly, pointing at the fancy-conformance checkout:
|
|   LFL_CONFORMANCE_DIR=../fancy-conformance vendor/bin/pest tests/Conformance
|
| It reads each scripts/fun-lab/<suite>.input.json there, runs every case
| through ScenarioRunner (one fresh database per case, which is why each case
| is its own test), and writes suites/fun-lab/<suite>/{manifest,cases}.json.
| A golden is therefore always what the engine did, never what someone typed.
|
*/

function funLabConformanceDir(): string
{
    $dir = getenv('LFL_CONFORMANCE_DIR');
    if (! is_string($dir) || $dir === '' || ! is_dir($dir.'/scripts/fun-lab')) {
        throw new RuntimeException('Set LFL_CONFORMANCE_DIR to a fancy-conformance checkout with scripts/fun-lab/.');
    }

    return rtrim(str_replace('\\', '/', $dir), '/');
}

/** @return list<array{0: string, 1: array<string, mixed>}> */
function funLabCases(): array
{
    $out = [];
    foreach (glob(funLabConformanceDir().'/scripts/fun-lab/*.input.json') ?: [] as $file) {
        $spec = json_decode((string) file_get_contents($file), true, flags: JSON_THROW_ON_ERROR);
        foreach ($spec['cases'] as $case) {
            $out[$spec['suite'].' '.$case['id']] = [$spec['suite'], $case];
        }
    }

    return $out;
}

/** The answer for one case, by the function it names. */
function funLabExpected(array $case): mixed
{
    return match ($case['fn']) {
        'slug' => Str::slug((string) $case['input']['value']),
        'headline' => Str::headline((string) $case['input']['value']),
        'scenario' => (new ScenarioRunner)->run($case['input']),
    };
}

$GLOBALS['funLabResults'] = [];

it('exports', function (string $suite, array $case) {
    $GLOBALS['funLabResults'][$suite][$case['id']] = funLabExpected($case);
    expect(true)->toBeTrue();
})->with(funLabCases());

afterAll(function () {
    $root = funLabConformanceDir();
    $version = trim((string) file_get_contents($root.'/VERSION'));

    foreach (glob($root.'/scripts/fun-lab/*.input.json') ?: [] as $file) {
        $spec = json_decode((string) file_get_contents($file), true, flags: JSON_THROW_ON_ERROR);
        $suite = (string) $spec['suite'];
        $dir = $root.'/suites/'.$suite;
        @mkdir($dir, 0777, true);

        $existing = is_file($dir.'/cases.json') ? json_decode((string) file_get_contents($dir.'/cases.json'), true) : null;
        $since = [];
        foreach ($existing['cases'] ?? [] as $c) {
            $since[$c['id']] = $c['since'];
        }

        $cases = [];
        foreach ($spec['cases'] as $case) {
            if (! array_key_exists($case['id'], $GLOBALS['funLabResults'][$suite] ?? [])) {
                throw new RuntimeException("{$suite} {$case['id']} produced no result; refusing to write a partial table.");
            }
            $row = ['id' => $case['id'], 'title' => $case['title'], 'since' => $since[$case['id']] ?? $version];
            foreach (['tags', 'fn'] as $k) {
                if (isset($case[$k])) {
                    $row[$k] = $case[$k];
                }
            }
            $row['input'] = $case['input'];
            $row['expected'] = $GLOBALS['funLabResults'][$suite][$case['id']];
            foreach (['skip', 'tolerance', 'notes'] as $k) {
                if (isset($case[$k])) {
                    $row[$k] = $case[$k];
                }
            }
            $cases[] = $row;
        }

        $manifest = ['$schema' => '../../../schema/suite-manifest.schema.json', 'suite' => $suite] + $spec['manifest'] + ['cases' => 'cases.json'];
        $flags = JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PRESERVE_ZERO_FRACTION | JSON_THROW_ON_ERROR;
        file_put_contents($dir.'/manifest.json', json_encode($manifest, $flags)."\n");
        file_put_contents($dir.'/cases.json', json_encode(['$schema' => '../../../schema/case-table.schema.json', 'suite' => $suite, 'cases' => $cases], $flags)."\n");
    }
});
