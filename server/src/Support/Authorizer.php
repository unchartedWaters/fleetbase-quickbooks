<?php

namespace Fleetbase\Quickbooks\Support;

use Fleetbase\Support\Auth;

class Authorizer
{
    /** @var callable|null */
    private $checker;

    public function __construct(?callable $checker = null)
    {
        $this->checker = $checker;
    }

    public function check(string $permission): void
    {
        $allowed = $this->checker !== null
            ? (bool) ($this->checker)($permission)
            : Auth::can($permission);

        if (!$allowed) {
            abort(403, 'This action is unauthorized.');
        }
    }
}
