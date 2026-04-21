<?php

declare(strict_types=1);

namespace LaravelFunLab\Tests\Fixtures;

use Closure;
use Illuminate\Http\Request;

/**
 * No-op middleware used as the `lfl.api.auth.middleware` value in the
 * package test suite. The sanity check in LFLServiceProvider::boot() only
 * requires SOME middleware to be wired — a consumer-side concern in real
 * deployments; tests simulate the auth layer with ->actingAs().
 */
class NoopMiddleware
{
    public function handle(Request $request, Closure $next): mixed
    {
        return $next($request);
    }
}
