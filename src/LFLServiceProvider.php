<?php

declare(strict_types=1);

namespace LaravelFunLab;

use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;
use LaravelFunLab\Console\Commands\InstallCommand;
use LaravelFunLab\Contracts\AnalyticsServiceContract;
use LaravelFunLab\Contracts\AwardEngineContract;
use LaravelFunLab\Contracts\LeaderboardServiceContract;
use LaravelFunLab\Listeners\EventLogSubscriber;
use LaravelFunLab\Registries\AwardTypeRegistry;
use LaravelFunLab\Services\AwardEngine;
use RuntimeException;

/**
 * Laravel Fun Lab Service Provider
 *
 * Bootstraps the LFL package by registering services, loading configuration,
 * migrations, routes, and views. Provides the foundation for the gamification layer.
 */
class LFLServiceProvider extends ServiceProvider
{
    /**
     * Register package services and bindings.
     */
    public function register(): void
    {
        // Merge the package config with application config
        $this->mergeConfigFrom(
            __DIR__.'/../config/lfl.php',
            'lfl'
        );

        $this->registerServiceBindings();
    }

    /**
     * Register swappable service bindings.
     *
     * Allows developers to swap implementations via config or service provider.
     */
    protected function registerServiceBindings(): void
    {
        // Register AwardEngine with contract binding
        $awardEngineClass = config('lfl.services.award_engine', AwardEngine::class);
        $this->app->singleton(AwardEngineContract::class, function ($app) use ($awardEngineClass) {
            return $app->make($awardEngineClass);
        });

        // Register the AwardEngine as a singleton for facade access (using contract)
        $this->app->singleton('lfl', function ($app) {
            return $app->make(AwardEngineContract::class);
        });

        // Register LeaderboardBuilder with contract binding
        $leaderboardClass = config('lfl.services.leaderboard', \LaravelFunLab\Builders\LeaderboardBuilder::class);
        $this->app->bind(LeaderboardServiceContract::class, function ($app) use ($leaderboardClass) {
            return $app->make($leaderboardClass);
        });

        // Register AnalyticsBuilder with contract binding
        $analyticsClass = config('lfl.services.analytics', \LaravelFunLab\Builders\AnalyticsBuilder::class);
        $this->app->bind(AnalyticsServiceContract::class, function ($app) use ($analyticsClass) {
            return $app->make($analyticsClass);
        });
    }

    /**
     * Bootstrap package services.
     */
    public function boot(): void
    {
        $this->assertWriteConfigIsSafe();
        $this->registerAwardTypes();
        $this->registerCommands();
        $this->registerPublishables();
        $this->registerMigrations();
        $this->registerRateLimiters();
        $this->registerRoutes();
        $this->registerViews();
        $this->registerEventSubscribers();
        $this->registerBroadcastChannels();
    }

    /**
     * Refuse to boot when writes are enabled but no auth middleware is wired.
     *
     * Without this guard, every POST endpoint (awards, grants, opt-in/out,
     * catalog setup) would be publicly callable. That's almost always a
     * configuration mistake — surface it loudly.
     */
    protected function assertWriteConfigIsSafe(): void
    {
        if (! config('lfl.api.enabled', true)) {
            return;
        }

        if (! config('lfl.api.writes', false)) {
            return;
        }

        $middleware = config('lfl.api.auth.middleware');
        if ($middleware === null || $middleware === '') {
            throw new RuntimeException(
                'LFL refuses to register write endpoints: config(lfl.api.writes)=true '
                .'but config(lfl.api.auth.middleware) is null. Set '
                .'LFL_API_AUTH_MIDDLEWARE (e.g. "auth:sanctum") or disable writes '
                .'with LFL_API_WRITES=false.'
            );
        }
    }

    /**
     * Register the `lfl-writes` named rate limiter used by write routes.
     */
    protected function registerRateLimiters(): void
    {
        $perMinute = (int) config('lfl.api.rate_limit.writes_per_minute', 60);

        RateLimiter::for('lfl-writes', function (Request $request) use ($perMinute) {
            $key = $request->user()?->getAuthIdentifier() ?? $request->ip();

            return Limit::perMinute($perMinute)->by((string) $key);
        });
    }

    /**
     * Register package broadcast channel authorizations.
     *
     * Only loaded when opt-in broadcasting is enabled via config. LFL does not
     * install a broadcasting driver — the consumer configures Reverb, Pusher,
     * or similar at the application layer.
     */
    protected function registerBroadcastChannels(): void
    {
        if (! config('lfl.events.broadcast', false)) {
            return;
        }

        if (! file_exists($path = __DIR__.'/../routes/channels.php')) {
            return;
        }

        require $path;
    }

    /**
     * Register custom award types from configuration.
     */
    protected function registerAwardTypes(): void
    {
        $configTypes = config('lfl.award_types', []);

        // Filter out built-in types and register only custom ones
        $builtInTypes = ['points', 'achievement', 'prize', 'badge'];
        $customTypes = Arr::except($configTypes, $builtInTypes);

        if (! empty($customTypes)) {
            AwardTypeRegistry::registerMany($customTypes);
        }
    }

    /**
     * Register artisan commands provided by the package.
     */
    protected function registerCommands(): void
    {
        if (! $this->app->runningInConsole()) {
            return;
        }

        $this->commands([
            InstallCommand::class,
        ]);
    }

    /**
     * Register event subscribers for the event pipeline.
     */
    protected function registerEventSubscribers(): void
    {
        // Register the EventLog subscriber if event logging is enabled
        if (config('lfl.events.log_to_database', true)) {
            Event::subscribe(EventLogSubscriber::class);
        }
    }

    /**
     * Register publishable assets for vendor:publish command.
     */
    protected function registerPublishables(): void
    {
        if (! $this->app->runningInConsole()) {
            return;
        }

        // Publish config file
        $this->publishes([
            __DIR__.'/../config/lfl.php' => config_path('lfl.php'),
        ], 'lfl-config');

        // Publish migrations
        $this->publishes([
            __DIR__.'/../database/migrations' => database_path('migrations'),
        ], 'lfl-migrations');

        // Publish views for customization
        $this->publishes([
            __DIR__.'/../resources/views' => resource_path('views/vendor/lfl'),
        ], 'lfl-views');

        // Publish OpenAPI spec so consumers can serve a local copy
        $this->publishes([
            __DIR__.'/../resources/openapi.yaml' => resource_path('openapi/lfl.yaml'),
        ], 'lfl-openapi');

        // Publish all assets at once
        $this->publishes([
            __DIR__.'/../config/lfl.php' => config_path('lfl.php'),
            __DIR__.'/../database/migrations' => database_path('migrations'),
            __DIR__.'/../resources/views' => resource_path('views/vendor/lfl'),
            __DIR__.'/../resources/openapi.yaml' => resource_path('openapi/lfl.yaml'),
        ], 'lfl');
    }

    /**
     * Register package migrations.
     */
    protected function registerMigrations(): void
    {
        // Only load migrations if configured to do so
        if (config('lfl.migrations', true)) {
            $this->loadMigrationsFrom(__DIR__.'/../database/migrations');
        }
    }

    /**
     * Register package routes.
     */
    protected function registerRoutes(): void
    {
        // Only load routes if the API is enabled
        if (config('lfl.api.enabled', true)) {
            $this->loadRoutesFrom(__DIR__.'/../routes/api.php');
        }

        // Only load web routes if the UI layer is enabled
        if (config('lfl.ui.enabled', false)) {
            $this->loadRoutesFrom(__DIR__.'/../routes/web.php');
        }
    }

    /**
     * Register package views.
     */
    protected function registerViews(): void
    {
        // Only load views if the UI layer is enabled
        if (config('lfl.ui.enabled', false)) {
            $this->loadViewsFrom(__DIR__.'/../resources/views', 'lfl');
        }
    }
}
