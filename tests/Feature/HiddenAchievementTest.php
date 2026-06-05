<?php

declare(strict_types=1);

use LaravelFunLab\Facades\LFL;
use LaravelFunLab\Models\Achievement;

/*
| Hidden ("secret"/Easter-egg) achievements — omitted from the public catalog
| (scopeVisible) until earned; still grantable like any other achievement.
*/

it('can set up a hidden achievement via LFL::setup', function () {
    $a = LFL::setup('achievement', [
        'slug' => 'the-adventurer',
        'name' => 'The Adventurer',
        'description' => 'Found the one true path.',
        'hidden' => true,
    ]);

    expect($a)->toBeInstanceOf(Achievement::class);
    expect($a->is_hidden)->toBeTrue();
});

it('defaults is_hidden to false', function () {
    $a = LFL::setup('achievement', ['slug' => 'plain', 'name' => 'Plain']);

    expect($a->is_hidden)->toBeFalse();
});

it('visible() excludes hidden and hidden() includes only hidden', function () {
    LFL::setup('achievement', ['slug' => 'public-one', 'name' => 'Public']);
    LFL::setup('achievement', ['slug' => 'secret-one', 'name' => 'Secret', 'hidden' => true]);

    $visible = Achievement::visible()->pluck('slug')->all();
    $hidden = Achievement::hidden()->pluck('slug')->all();

    expect($visible)->toContain('public-one')->not->toContain('secret-one');
    expect($hidden)->toContain('secret-one')->not->toContain('public-one');
});
