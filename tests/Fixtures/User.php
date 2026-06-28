<?php

declare(strict_types=1);

namespace Andre\AiPageBuilder\Tests\Fixtures;

use Illuminate\Foundation\Auth\User as Authenticatable;

/**
 * Minimal authenticatable for exercising the auth-gated panel endpoints.
 */
class User extends Authenticatable
{
    protected $guarded = [];

    public function getAuthIdentifier(): int
    {
        return 1;
    }
}
