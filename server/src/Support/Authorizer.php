<?php

namespace Fleetbase\Quickbooks\Support;

use Fleetbase\Support\Auth;
use Illuminate\Http\Request;

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
        // Installation administrators keep working without a QuickBooks role.
        // Everyone else needs the permission the extension schema grants.
        if ($this->signedInInstallationAdmin() === true) {
            return;
        }

        // show() and test() share view connection, which read-only also has.
        // The connection test has no schema action, so it stays installation-admin only.
        if ($this->granted($permission) === false || $this->isConnectionTest($permission) === true) {
            abort(403, 'This action is unauthorized.');
        }
    }

    private function granted(string $permission): bool
    {
        if ($this->checker !== null) {
            return (bool) ($this->checker)($permission);
        }

        return Auth::can($permission);
    }

    /**
     * A permission stub with no signed-in user is not an installation administrator.
     * That stub only answers granted().
     */
    private function signedInInstallationAdmin(): bool
    {
        $request = $this->callerRequest();
        $user    = $request instanceof Request === true ? $request->user() : null;
        if (is_object($user) === true) {
            return InstallationAdmin::isInstallationAdmin($user);
        }

        if ($this->checker !== null) {
            return false;
        }

        return InstallationAdmin::allows($request);
    }

    private function isConnectionTest(string $permission): bool
    {
        if ($permission !== 'quickbooks view connection') {
            return false;
        }

        $request = $this->callerRequest();

        return $request instanceof Request === true && str_contains('/' . $request->path(), '/connection/test') === true;
    }

    private function callerRequest(): ?Request
    {
        foreach (debug_backtrace(0, 15) as $frame) {
            if (is_array($frame) === false) {
                continue;
            }
            $arguments = $frame['args'] ?? null;
            if (is_array($arguments) === false) {
                continue;
            }
            foreach ($arguments as $argument) {
                if ($argument instanceof Request === true) {
                    return $argument;
                }
            }
        }

        return null;
    }
}
