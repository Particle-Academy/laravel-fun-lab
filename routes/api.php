<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Route;
use LaravelFunLab\Http\Controllers\AchievementController;
use LaravelFunLab\Http\Controllers\AchievementSetupController;
use LaravelFunLab\Http\Controllers\AwardActionController;
use LaravelFunLab\Http\Controllers\AwardsController;
use LaravelFunLab\Http\Controllers\GamedMetricController;
use LaravelFunLab\Http\Controllers\GrantActionController;
use LaravelFunLab\Http\Controllers\LeaderboardController;
use LaravelFunLab\Http\Controllers\MeController;
use LaravelFunLab\Http\Controllers\MetricLevelController;
use LaravelFunLab\Http\Controllers\OpenApiController;
use LaravelFunLab\Http\Controllers\OptInController;
use LaravelFunLab\Http\Controllers\PrizeController;
use LaravelFunLab\Http\Controllers\ProfileController;

/**
 * Laravel Fun Lab API Routes
 *
 * Loaded by LFLServiceProvider only when config('lfl.api.enabled') is true.
 *
 * Authentication is the consumer's contract — set
 * config('lfl.api.auth.middleware') to any Laravel middleware that resolves
 * auth()->user() to an Awardable model. LFL ships no auth implementation.
 *
 * Write endpoints (POST) are gated by config('lfl.api.writes'), require an
 * allowlisted awardable_type (config('lfl.awardables')), and pass through
 * the consumer-configured authorization callable (config('lfl.authorize.*')).
 * Catalog-mutating POSTs (/gamed-metrics, /achievements, /prizes,
 * /metric-levels) are additionally gated by config('lfl.api.allow_setup_over_http').
 *
 * The `lfl-writes` rate limiter caps writes per user/IP per minute.
 */
$middleware = config('lfl.api.middleware', ['api']);

$authMiddleware = config('lfl.api.auth.middleware');
if ($authMiddleware !== null && $authMiddleware !== '') {
    $middleware[] = $authMiddleware;
}

Route::prefix(config('lfl.api.prefix', 'api/lfl'))
    ->middleware($middleware)
    ->group(function () {
        // Spec
        Route::get('/openapi.json', [OpenApiController::class, 'show']);

        // Current-user convenience endpoints (rely on consumer-configured auth)
        Route::get('/me/profile', [MeController::class, 'profile']);
        Route::get('/me/achievements', [MeController::class, 'achievements']);
        Route::get('/me/awards', [MeController::class, 'awards']);

        // Awardable lookups
        Route::get('/profiles/{type}/{id}', [ProfileController::class, 'show']);
        Route::get('/awards/{type}/{id}', [AwardsController::class, 'index']);

        // Catalog (read)
        Route::get('/leaderboards/{type}', [LeaderboardController::class, 'index']);
        Route::get('/achievements', [AchievementController::class, 'index']);
        Route::get('/gamed-metrics', [GamedMetricController::class, 'index']);
        Route::get('/metric-levels/{metric}', [MetricLevelController::class, 'index']);
        Route::get('/prizes', [PrizeController::class, 'index']);

        // Writes — gated by config('lfl.api.writes') AND rate-limited
        if (config('lfl.api.writes', false)) {
            Route::middleware('throttle:lfl-writes')->group(function () {
                Route::post('/awards', [AwardActionController::class, 'store']);
                Route::post('/grants', [GrantActionController::class, 'store']);

                Route::post('/profiles/{type}/{id}/opt-in', [OptInController::class, 'in']);
                Route::post('/profiles/{type}/{id}/opt-out', [OptInController::class, 'out']);

                // Setup (catalog creation) — additionally gated by a second
                // config flag since upsert-by-slug over HTTP is a higher-
                // privilege act than awarding XP.
                if (config('lfl.api.allow_setup_over_http', false)) {
                    Route::post('/gamed-metrics', [GamedMetricController::class, 'store']);
                    Route::post('/metric-levels', [MetricLevelController::class, 'store']);
                    Route::post('/achievements', [AchievementSetupController::class, 'store']);
                    Route::post('/prizes', [PrizeController::class, 'store']);
                }
            });
        }
    });
