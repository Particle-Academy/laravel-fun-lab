<?php

declare(strict_types=1);

namespace LaravelFunLab\Tests;

use Illuminate\Contracts\Console\Kernel as ConsoleKernel;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Bootstrap\LoadConfiguration;
use Illuminate\Foundation\Bootstrap\RegisterProviders;
use Illuminate\Foundation\Testing\TestCase as BaseTestCase;
use Illuminate\Support\Facades\Artisan;
use LaravelFunLab\LFLServiceProvider;

/**
 * Base TestCase for Laravel Fun Lab package tests.
 *
 * A hand-built Laravel application (laravel/framework only, no
 * orchestra/testbench): an empty skeleton in a temp directory, the
 * framework's default configuration, this package's provider, and a fresh
 * in-memory SQLite database for every test. Nothing leaves the process.
 */
abstract class TestCase extends BaseTestCase
{
    /**
     * Artisan calls return their exit code instead of a mocked PendingCommand,
     * so the suite needs no mocking library. Read output with Artisan::output().
     *
     * @var bool
     */
    public $mockConsoleOutput = false;

    public function createApplication(): Application
    {
        $base = sys_get_temp_dir().DIRECTORY_SEPARATOR.'laravel-fun-lab-tests';
        foreach (['bootstrap/cache', 'config', 'database/migrations', 'resources/views', 'storage/framework/views', 'storage/framework/cache', 'storage/framework/sessions', 'storage/logs'] as $dir) {
            if (! is_dir($base.'/'.$dir)) {
                mkdir($base.'/'.$dir, 0777, true);
            }
        }
        // The framework reads the skeleton's .env; an empty one means "no overrides".
        if (! is_file($base.'/.env')) {
            touch($base.'/.env');
        }
        // A config file `lfl:install` published in an earlier run must not
        // become this run's configuration.
        if (is_file($base.'/config/lfl.php')) {
            unlink($base.'/config/lfl.php');
        }

        $app = Application::configure(basePath: $base)
            ->withProviders($this->getPackageProviders(), withBootstrapProviders: false)
            ->withMiddleware()
            ->withExceptions()
            ->create();

        $app->afterBootstrapping(LoadConfiguration::class, function (Application $app): void {
            // Always the testing environment: runningUnitTests() keys off it,
            // and lfl:install would otherwise wait on a prompt.
            $app->detectEnvironment(fn (): string => 'testing');
            $app['config']->set('app.env', 'testing');
            $app['config']->set('app.key', 'base64:'.base64_encode(str_repeat('k', 32)));
            $app['config']->set('app.aliases', array_merge((array) $app['config']->get('app.aliases', []), $this->getPackageAliases()));
            $app['config']->set('mail.default', 'array');
            $app['config']->set('queue.default', 'sync');
            $app['config']->set('session.driver', 'array');
            // No provider keys, ever, in a test run.
            $app['config']->set('services.anthropic.key', '');
            $app['config']->set('services.openai.key', '');
        });

        // After the providers have merged their default config and before any
        // of them boots, as testbench did: an override here replaces one key
        // and keeps the package's defaults around it.
        $app->afterBootstrapping(RegisterProviders::class, function (Application $app): void {
            $this->defineEnvironment($app);
        });

        $app->make(ConsoleKernel::class)->bootstrap();

        return $app;
    }

    /**
     * Routes a test registers after boot (WebUiTest loads routes/web.php by
     * hand) are not in the router's name lookup until it is refreshed, so
     * route('lfl.…') would throw inside a view. Refresh before every request,
     * as testbench did.
     *
     * @return \Illuminate\Testing\TestResponse<\Symfony\Component\HttpFoundation\Response>
     */
    public function call($method, $uri, $parameters = [], $cookies = [], $files = [], $server = [], $content = null)
    {
        $routes = $this->app['router']->getRoutes();
        $routes->refreshNameLookups();
        $routes->refreshActionLookups();

        return parent::call($method, $uri, $parameters, $cookies, $files, $server, $content);
    }

    /**
     * Setup the test environment.
     */
    protected function setUp(): void
    {
        parent::setUp();

        Artisan::call('migrate', ['--force' => true]);

        $this->setUpDatabase();
    }

    /**
     * Get package providers.
     *
     * @return array<int, class-string>
     */
    protected function getPackageProviders(): array
    {
        return [
            LFLServiceProvider::class,
        ];
    }

    /**
     * Get package aliases.
     *
     * @return array<string, class-string>
     */
    protected function getPackageAliases(): array
    {
        return [
            'LFL' => \LaravelFunLab\Facades\LFL::class,
        ];
    }

    /**
     * Define environment setup.
     *
     * @param  \Illuminate\Foundation\Application  $app
     */
    protected function defineEnvironment($app): void
    {
        // Setup default database to use SQLite memory
        $app['config']->set('database.default', 'testing');
        $app['config']->set('database.connections.testing', [
            'driver' => 'sqlite',
            'database' => ':memory:',
            'prefix' => '',
        ]);

        // Use array cache so the `throttle:lfl-writes` rate limiter doesn't
        // require a `cache` DB table (the framework default is `database`).
        $app['config']->set('cache.default', 'array');

        // LFL config defaults
        $app['config']->set('lfl.table_prefix', 'lfl_');
        $app['config']->set('lfl.migrations', true);
        $app['config']->set('lfl.events.dispatch', true);

        // Test-friendly API defaults:
        //  - writes=true so feature tests can exercise POST endpoints
        //  - allow_setup_over_http=true so catalog POSTs are reachable
        //  - auth.middleware=NoopMiddleware so the boot-time sanity check
        //    in LFLServiceProvider passes — the actual auth layer is a
        //    consumer concern that tests simulate with ->actingAs()
        //  - awardables includes both fixture User types
        //  - authorize.allow_missing=true so writes only require an
        //    authenticated request (no specific callable); individual tests
        //    that need deny-by-default behavior override this
        $app['config']->set('lfl.api.enabled', true);
        $app['config']->set('lfl.api.writes', true);
        $app['config']->set('lfl.api.allow_setup_over_http', true);
        $app['config']->set('lfl.api.auth.middleware', \LaravelFunLab\Tests\Fixtures\NoopMiddleware::class);
        $app['config']->set('lfl.awardables', [
            \LaravelFunLab\Tests\Fixtures\User::class,
            \LaravelFunLab\Tests\Fixtures\AuthUser::class,
        ]);
        $app['config']->set('lfl.authorize.allow_missing', true);
    }

    /**
     * Set up the database with test tables.
     */
    protected function setUpDatabase(): void
    {
        // Create a test users table for the Awardable trait tests
        $this->app['db']->connection()->getSchemaBuilder()->create('users', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('email')->unique();
            $table->timestamps();
        });
    }
}
