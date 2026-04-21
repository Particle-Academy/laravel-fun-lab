<?php

declare(strict_types=1);

namespace LaravelFunLab\Http;

/**
 * AuthorizerRegistry
 *
 * In-memory registry for LFL authorization callables. Lives outside the
 * config array because Laravel's `php artisan config:cache` uses
 * `var_export()` to serialize config and rejects closures.
 *
 * Consumers register callables in a service provider's boot() method:
 *
 *   AuthorizerRegistry::set('award', fn ($user, $ctx) => $ctx['recipient']->id === $user->id);
 *
 * The AuthorizesLflActions trait consults this registry first, then falls
 * back to config('lfl.authorize.{action}') for FQCN-or-null entries.
 */
class AuthorizerRegistry
{
    /** @var array<string, callable|null> */
    protected static array $callables = [];

    public static function set(string $action, ?callable $callable): void
    {
        static::$callables[$action] = $callable;
    }

    public static function get(string $action): ?callable
    {
        return static::$callables[$action] ?? null;
    }

    public static function has(string $action): bool
    {
        return isset(static::$callables[$action]);
    }

    public static function flush(): void
    {
        static::$callables = [];
    }
}
