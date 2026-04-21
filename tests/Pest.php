<?php

declare(strict_types=1);

/*
|--------------------------------------------------------------------------
| Test Case
|--------------------------------------------------------------------------
|
| The closure you provide to your test functions is always bound to a specific
| PHPUnit test case class. By default, that class is "PHPUnit\Framework\TestCase".
| We use the package TestCase that extends Orchestra Testbench.
|
*/

use LaravelFunLab\Tests\TestCase;
use LaravelFunLab\Tests\WritesDisabledTestCase;

uses(TestCase::class)->in('Unit', 'Feature');

// Writes-gate tests need config('lfl.api.writes') set to false BEFORE the
// LFLServiceProvider boots, so they use a dedicated TestCase subclass and
// live in their own folder (Pest binds TestCase per folder, not per file).
uses(WritesDisabledTestCase::class)->in('ApiGate');

/*
|--------------------------------------------------------------------------
| Expectations
|--------------------------------------------------------------------------
|
| When you're writing tests, you often need to check that values meet certain conditions. The
| "expect()" function gives you access to a set of "expectations" methods that you can use
| to assert different things. Of course, you may extend the Expectation API at any time.
|
*/

expect()->extend('toBeAwardResult', function () {
    return $this->toBeInstanceOf(\LaravelFunLab\ValueObjects\AwardResult::class);
});

expect()->extend('toBeSuccessfulAward', function () {
    return $this
        ->toBeAwardResult()
        ->success->toBeTrue();
});

expect()->extend('toBeFailedAward', function () {
    return $this
        ->toBeAwardResult()
        ->success->toBeFalse();
});

/*
|--------------------------------------------------------------------------
| Functions
|--------------------------------------------------------------------------
|
| While Pest is very powerful out-of-the-box, you may have some testing code specific to your
| project that you don't want to repeat in every file. Here you can also expose helpers as
| global functions to help you to reduce the number of lines of code in your test files.
|
*/
