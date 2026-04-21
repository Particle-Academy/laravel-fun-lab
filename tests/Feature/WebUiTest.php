<?php

declare(strict_types=1);

use LaravelFunLab\Facades\LFL;
use LaravelFunLab\Models\Achievement;
use LaravelFunLab\Models\GamedMetric;
use LaravelFunLab\Models\Prize;
use LaravelFunLab\Models\Profile;
use LaravelFunLab\Tests\Fixtures\User;

/*
|--------------------------------------------------------------------------
| Web UI Tests
|--------------------------------------------------------------------------
|
| Tests for the optional UI layer including leaderboard, profile,
| and admin dashboard views.
|
*/

beforeEach(function () {
    // Set app key for web middleware
    $this->app['config']->set('app.key', 'base64:'.base64_encode(random_bytes(32)));

    // Enable UI in config
    $this->app['config']->set('lfl.ui.enabled', true);
    $this->app['config']->set('lfl.ui.prefix', 'lfl');

    // Manually register views since service provider checks config during boot
    $viewFinder = $this->app['view']->getFinder();
    $viewFinder->addNamespace('lfl', __DIR__.'/../../resources/views');

    // Manually load web routes since service provider checks config during boot
    if (file_exists(__DIR__.'/../../routes/web.php')) {
        require __DIR__.'/../../routes/web.php';
    }
});

describe('Web UI Routes', function () {

    describe('Leaderboard View', function () {

        it('displays leaderboard page', function () {
            $user1 = User::create(['name' => 'User 1', 'email' => 'user1@example.com']);
            $user2 = User::create(['name' => 'User 2', 'email' => 'user2@example.com']);

            $profile1 = $user1->getProfile();
            $profile2 = $user2->getProfile();

            $profile1->setAggregates(['total_xp' => 200]);
            $profile2->setAggregates(['total_xp' => 100]);

            $response = $this->get('/lfl/leaderboards/'.urlencode(User::class));

            $response->assertSuccessful()
                ->assertViewIs('lfl::leaderboard')
                ->assertViewHas('leaderboard')
                ->assertViewHas('type', User::class);
        });

        it('supports filtering by metric', function () {
            $user = User::create(['name' => 'User', 'email' => 'user@example.com']);
            $profile = $user->getProfile();
            $profile->setAggregates(['achievement_count' => 5]);

            $response = $this->get('/lfl/leaderboards/'.urlencode(User::class).'?by=achievements');

            $response->assertSuccessful()
                ->assertViewHas('sortBy', 'achievements');
        });

    });

    describe('Profile View', function () {

        it('displays profile page', function () {
            $user = User::create(['name' => 'Test User', 'email' => 'test@example.com']);
            $profile = $user->getProfile();
            $profile->setAggregates([
                'total_xp' => 150,
                'achievement_count' => 3,
                'prize_count' => 2,
            ]);

            $response = $this->get('/lfl/profiles/'.urlencode(User::class).'/'.$user->id);

            $response->assertSuccessful()
                ->assertViewIs('lfl::profile')
                ->assertViewHas('profile')
                ->assertViewHas('recentAwards')
                ->assertViewHas('achievements');
        });

        it('returns 404 for non-existent profile', function () {
            $response = $this->get('/lfl/profiles/'.urlencode(User::class).'/999');

            $response->assertNotFound();
        });

    });

    describe('Admin Dashboard', function () {

        it('displays admin dashboard', function () {
            $response = $this->get('/lfl/admin');

            $response->assertSuccessful()
                ->assertViewIs('lfl::admin.index')
                ->assertViewHas('stats');
        });

        it('displays achievements management page', function () {
            Achievement::create([
                'slug' => 'test-achievement',
                'name' => 'Test Achievement',
                'is_active' => true,
            ]);

            $response = $this->get('/lfl/admin/achievements');

            $response->assertSuccessful()
                ->assertViewIs('lfl::admin.achievements')
                ->assertViewHas('achievements');
        });

        it('displays prizes management page', function () {
            Prize::create([
                'slug' => 'test-prize',
                'name' => 'Test Prize',
                'type' => \LaravelFunLab\Enums\PrizeType::Virtual,
            ]);

            $response = $this->get('/lfl/admin/prizes');

            $response->assertSuccessful()
                ->assertViewIs('lfl::admin.prizes')
                ->assertViewHas('prizes');
        });

        it('displays analytics page', function () {
            // Create a GamedMetric using LFL::setup()
            LFL::setup(
                a: 'gamed-metric',
                with: [
                    'slug' => 'general-xp',
                    'name' => 'General XP',
                    'description' => 'General experience points',
                    'active' => true,
                ]
            );

            $user = User::create(['name' => 'User', 'email' => 'user@example.com']);
            $user->getProfile();

            // Award XP using LFL::award()
            LFL::award('general-xp')->to($user)->amount(50)->save();

            $response = $this->get('/lfl/admin/analytics');

            $response->assertSuccessful()
                ->assertViewIs('lfl::admin.analytics')
                ->assertViewHas('xpOverTime')
                ->assertViewHas('topEarners');
        });

        it('supports period filtering in analytics', function () {
            $response = $this->get('/lfl/admin/analytics?period=7');

            $response->assertSuccessful()
                ->assertViewHas('period', '7');
        });

    });

});
