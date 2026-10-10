<?php

namespace Fleetbase\Quickbooks\Tests\Support;

use Illuminate\Http\Request;

/**
 * A request signed in as an installation administrator (User::isAdmin() is true).
 * Saving install-wide QuickBooks settings is refused for everyone else.
 */
class InstallAdminRequest
{
    /**
     * @param array<string, mixed> $parameters
     */
    public static function create(string $uri, string $method = 'GET', array $parameters = [], bool $admin = true): Request
    {
        $request = Request::create($uri, $method, $parameters);
        $request->setUserResolver(static fn () => new class($admin) {
            public function __construct(private bool $admin)
            {
            }

            public function isAdmin(): bool
            {
                return $this->admin;
            }
        });

        return $request;
    }
}
