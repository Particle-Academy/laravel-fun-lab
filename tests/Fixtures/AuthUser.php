<?php

declare(strict_types=1);

namespace LaravelFunLab\Tests\Fixtures;

use Illuminate\Foundation\Auth\User as Authenticatable;
use LaravelFunLab\Traits\Awardable;

/**
 * Test Authenticatable User Model
 *
 * A fixture that satisfies both Authenticatable and Awardable for /me endpoint
 * API tests. Uses the same `users` table as the plain User fixture.
 */
class AuthUser extends Authenticatable
{
    use Awardable;

    protected $fillable = ['name', 'email'];

    protected $table = 'users';
}
