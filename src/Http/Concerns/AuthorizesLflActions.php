<?php

declare(strict_types=1);

namespace LaravelFunLab\Http\Concerns;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * AuthorizesLflActions
 *
 * Trait used by every LFL write controller to invoke the consumer-configured
 * authorization callable before executing a mutation. Deny-by-default: when
 * no callable is registered AND `authorize.allow_missing` is false (the
 * default), all writes return 403.
 *
 * Authorization callables receive `(?Model $user, array $context)` and must
 * return a truthy value to allow.
 */
trait AuthorizesLflActions
{
    /**
     * Authorize an action; returns null on success or a 403 JsonResponse.
     *
     * @param  string  $action  One of: award, grant, opt, setup
     * @param  array<string, mixed>  $context  Action-specific context
     */
    protected function authorizeAction(Request $request, string $action, array $context): ?JsonResponse
    {
        $user = $request->user();
        // Callables live in AuthorizerRegistry (not config) because
        // config:cache serializes config via var_export() and rejects
        // closures. Fall back to config for FQCN strings or null.
        $callable = \LaravelFunLab\Http\AuthorizerRegistry::get($action)
            ?? config("lfl.authorize.{$action}");
        $allowMissing = (bool) config('lfl.authorize.allow_missing', false);

        if ($callable === null) {
            if ($allowMissing) {
                // Dev-mode escape hatch: no callable AND consumer opted into
                // "skip the check entirely". Authenticated or not, pass.
                return null;
            }

            return response()->json([
                'message' => "No authorization callable configured for 'lfl.authorize.{$action}'. "
                    ."Set one in config/lfl.php, or enable 'authorize.allow_missing' for development.",
            ], 403);
        }

        $allowed = $this->invokeAuthorizer($callable, $user, $context);

        if (! $allowed) {
            return response()->json(['message' => "Not authorized to {$action}."], 403);
        }

        return null;
    }

    /**
     * Invoke a callable OR an FQCN with an `authorize(?Model, array): bool` method.
     */
    protected function invokeAuthorizer(mixed $callable, mixed $user, array $context): bool
    {
        if (is_string($callable) && class_exists($callable)) {
            $instance = app($callable);
            if (method_exists($instance, 'authorize')) {
                return (bool) $instance->authorize($user, $context);
            }

            return false;
        }

        if (is_callable($callable)) {
            return (bool) $callable($user, $context);
        }

        return false;
    }
}
