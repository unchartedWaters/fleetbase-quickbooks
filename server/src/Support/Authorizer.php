<?php

namespace Fleetbase\Quickbooks\Support;

use Fleetbase\Support\Auth;
use Illuminate\Http\Request;

class Authorizer
{
    /** @var array<int, string> */
    private const ADMIN_PERMISSIONS = [
        'quickbooks view settings',
        'quickbooks update settings',
        'quickbooks connect connection',
        'quickbooks disconnect connection',
        'quickbooks import-customers connection',
        'quickbooks reconcile sync',
    ];

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

        if (!$allowed || ($this->requiresInstallationAdmin($permission) && !$this->isInstallationAdmin())) {
            abort(403, 'This action is unauthorized.');
        }
    }

    private function requiresInstallationAdmin(string $permission): bool
    {
        if (in_array($permission, self::ADMIN_PERMISSIONS, true)) {
            return true;
        }

        // show() and test() share this permission. Only the connection test posts here.
        return $permission === 'quickbooks view connection' && $this->isConnectionTestRequest();
    }

    private function isConnectionTestRequest(): bool
    {
        $request = $this->callerRequest();

        return $request instanceof Request && str_contains('/' . $request->path(), '/connection/test');
    }

    /**
     * A permission stub with no signed-in user stands in for an authorized caller.
     * A signed-in user must be an installation administrator (User::isAdmin()).
     */
    private function isInstallationAdmin(): bool
    {
        $request = $this->callerRequest();
        $user    = $request instanceof Request ? $request->user() : null;
        if (is_object($user)) {
            return InstallationAdmin::isInstallationAdmin($user);
        }

        if ($this->checker !== null) {
            return true;
        }

        return InstallationAdmin::allows($request);
    }

    private function callerRequest(): ?Request
    {
        foreach (debug_backtrace(0, 15) as $frame) {
            if (!is_array($frame)) {
                continue;
            }
            $arguments = $frame['args'] ?? null;
            if (!is_array($arguments)) {
                continue;
            }
            foreach ($arguments as $argument) {
                if ($argument instanceof Request) {
                    return $argument;
                }
            }
        }

        return null;
    }
}
