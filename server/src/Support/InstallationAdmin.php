<?php

namespace Fleetbase\Quickbooks\Support;

use Fleetbase\Support\Auth;
use Illuminate\Http\Request;

/**
 * Installation administrator, the same check as Fleetbase's AdminGuard: User::isAdmin().
 */
class InstallationAdmin
{
    public static function isInstallationAdmin(mixed $user): bool
    {
        if (is_object($user) === false) {
            return false;
        }
        if (method_exists($user, 'isAdmin') === true) {
            return $user->isAdmin() === true;
        }

        $values = get_object_vars($user);

        return ($values['type'] ?? null) === 'admin';
    }

    public static function allows(?Request $request = null): bool
    {
        if (class_exists(Auth::class) === false) {
            return false;
        }

        try {
            $user = Auth::getUserFromSession($request);
        } catch (\Throwable) {
            return false;
        }

        return self::isInstallationAdmin($user);
    }
}
