<?php

declare(strict_types=1);

namespace LaravelFunLab\Tests;

/**
 * TestCase variant that disables LFL's API write endpoints at environment
 * setup time — before LFLServiceProvider::boot() runs — so the route
 * registration reflects `api.writes = false`.
 *
 * Flipping config('lfl.api.writes') inside a test body doesn't work: the
 * service provider has already registered (or declined to register) the
 * POST routes by then.
 */
abstract class WritesDisabledTestCase extends TestCase
{
    protected function defineEnvironment($app): void
    {
        parent::defineEnvironment($app);

        $app['config']->set('lfl.api.enabled', true);
        $app['config']->set('lfl.api.writes', false);
        $app['config']->set('lfl.api.auth.middleware', null);
    }
}
