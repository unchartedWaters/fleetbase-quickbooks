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

    /**
     * The request is passed in by the controller. An installation administrator keeps working
     * without a QuickBooks role. Everyone else needs the permission the extension schema grants.
     */
    public function check(string $permission, ?Request $request = null): void
    {
        if ($this->isInstallationAdmin($request) === true) {
            return;
        }

        if ($this->granted($permission) === false) {
            abort(403, 'This action is unauthorized.');
        }
    }

    /**
     * Install-wide settings and the connection test belong to the installation administrator.
     * An organization role, even Administrator, is not enough.
     */
    public function checkInstallationAdmin(?Request $request = null): void
    {
        if ($this->isInstallationAdmin($request) === false) {
            abort(403, 'This action is unauthorized.');
        }
    }

    /**
     * A permission stub with no signed-in user is not an installation administrator.
     * That stub only answers granted().
     */
    public function isInstallationAdmin(?Request $request = null): bool
    {
        $user = $request instanceof Request === true ? $request->user() : null;
        if (is_object($user) === true) {
            return InstallationAdmin::isInstallationAdmin($user);
        }

        if ($this->checker !== null) {
            return false;
        }

        return InstallationAdmin::allows($request);
    }

    private function granted(string $permission): bool
    {
        if ($this->checker !== null) {
            return (bool) ($this->checker)($permission);
        }

        return Auth::can($permission);
    }
}
