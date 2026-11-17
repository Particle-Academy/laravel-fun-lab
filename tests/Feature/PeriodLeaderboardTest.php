<?php

declare(strict_types=1);

use Illuminate\Support\Carbon;
use LaravelFunLab\Facades\LFL;
use LaravelFunLab\Tests\Fixtures\User;

/*
|--------------------------------------------------------------------------
| A period board ranks on XP EARNED in the period
|--------------------------------------------------------------------------
|
| Until 0.8.0 a daily / weekly / monthly board summed the LIFETIME total_xp of
| every metric touched in the period, so a profile with 10,000 old XP that
| earned 1 XP on Monday scored 10,000 on the weekly board. The score is now
| the sum of `xp_awarded` amounts in the event log since the period start.
|
*/

beforeEach(function () {
    // A Wednesday, so "this week" started two days ago (Monday 00:00).
    Carbon::setTestNow(Carbon::parse('2026-11-18 12:00:00'));

    LFL::setup(a: 'gamed-metric', with: ['slug' => 'combat']);
    LFL::setup(a: 'gamed-metric', with: ['slug' => 'crafting']);

    $this->veteran = User::create(['name' => 'Veteran', 'email' => 'veteran@example.com']);
    $this->newcomer = User::create(['name' => 'Newcomer', 'email' => 'newcomer@example.com']);
});

afterEach(function () {
    Carbon::setTestNow();
});

/** Award XP at a moment other than now. */
function awardAt(string $when, string $metric, User $to, int $amount): void
{
    $now = Carbon::now();
    Carbon::setTestNow(Carbon::parse($when));
    LFL::award($metric)->to($to)->amount($amount)->save();
    Carbon::setTestNow($now);
}

it('ranks on XP earned this week, not lifetime XP', function () {
    awardAt('2026-09-01 10:00:00', 'combat', $this->veteran, 10000);
    LFL::award('combat')->to($this->veteran)->amount(1)->save();
    LFL::award('combat')->to($this->newcomer)->amount(50)->save();

    $board = LFL::leaderboard()->for(User::class)->period('weekly')->get();

    expect($board->pluck('awardable_id')->all())->toBe([$this->newcomer->id, $this->veteran->id])
        ->and((int) $board[0]->period_xp)->toBe(50)
        ->and((int) $board[1]->period_xp)->toBe(1);
});

it('scopes the period score to one metric on a metric board', function () {
    awardAt('2026-09-01 10:00:00', 'combat', $this->veteran, 10000);
    LFL::award('combat')->to($this->veteran)->amount(5)->save();
    LFL::award('crafting')->to($this->veteran)->amount(900)->save();
    LFL::award('combat')->to($this->newcomer)->amount(40)->save();

    $board = LFL::leaderboard()->for(User::class)->metric('combat')->period('weekly')->get();

    expect($board->pluck('awardable_id')->all())->toBe([$this->newcomer->id, $this->veteran->id])
        ->and((int) $board[1]->period_xp)->toBe(5)
        ->and((int) $board[1]->metric_xp)->toBe(10005);
});

it('leaves out XP earned one second before the period starts', function () {
    awardAt('2026-11-15 23:59:59', 'combat', $this->veteran, 70);
    awardAt('2026-11-16 00:00:00', 'combat', $this->newcomer, 30);

    $board = LFL::leaderboard()->for(User::class)->period('weekly')->get();

    expect($board->pluck('awardable_id')->all())->toBe([$this->newcomer->id]);
});

it('omits profiles that earned nothing in the period', function () {
    awardAt('2026-10-01 10:00:00', 'combat', $this->veteran, 500);
    LFL::award('combat')->to($this->newcomer)->amount(1)->save();

    $daily = LFL::leaderboard()->for(User::class)->period('daily')->get();
    $monthly = LFL::leaderboard()->for(User::class)->period('monthly')->get();

    expect($daily->pluck('awardable_id')->all())->toBe([$this->newcomer->id])
        ->and($monthly->pluck('awardable_id')->all())->toBe([$this->newcomer->id]);
});

it('refuses a period board when the event log is off, rather than ranking nothing', function () {
    config(['lfl.events.log_to_database' => false]);

    expect(fn () => LFL::leaderboard()->for(User::class)->period('weekly')->get())
        ->toThrow(InvalidArgumentException::class, 'event log');
});

it('answers 422 over REST when the event log is off', function () {
    config(['lfl.events.log_to_database' => false]);

    $this->getJson('/api/lfl/leaderboards/'.urlencode(User::class).'?period=weekly')
        ->assertStatus(422)
        ->assertJsonValidationErrors('period');
});

it('still ranks an all-time board on the profile total', function () {
    awardAt('2026-09-01 10:00:00', 'combat', $this->veteran, 10000);
    LFL::award('combat')->to($this->newcomer)->amount(50)->save();

    $board = LFL::leaderboard()->for(User::class)->period('all-time')->get();

    expect($board->pluck('awardable_id')->all())->toBe([$this->veteran->id, $this->newcomer->id]);
});
