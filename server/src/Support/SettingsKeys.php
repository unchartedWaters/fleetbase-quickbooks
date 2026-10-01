<?php

namespace Fleetbase\Quickbooks\Support;

class SettingsKeys
{
    public static function companyAuth(string $companyUuid): string
    {
        return 'company.' . $companyUuid . '.quickbooks.auth';
    }

    public static function adminAuth(): string
    {
        return 'system.quickbooks.auth';
    }

    public static function companySync(string $companyUuid): string
    {
        return 'company.' . $companyUuid . '.quickbooks.sync';
    }

    public static function adminSync(): string
    {
        return 'system.quickbooks.sync';
    }
}
