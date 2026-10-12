<?php

namespace Fleetbase\Quickbooks\Support;

class SettingsKeys
{
    /**
     * Marks an organization sync row written by this build. Rows without it (older builds
     * stored organization rows too) are ignored so they cannot override the global defaults.
     */
    public const ORGANIZATION_MARKER = 'scope';

    public const ORGANIZATION_MARKER_VALUE = 'organization';

    /**
     * Sync options an organization holds its own copy of. Credentials, the webhook verifier, the
     * environment and the public URLs are global and are never stored on an organization row.
     *
     * @var array<int, string>
     */
    public const SYNC_FIELDS = [
        'enabled',
        'interval_minutes',
        'periodic_interval_hours',
        'batch_size',
        'retry_limit',
        'default_backoff_seconds',
        'customer_conflict',
        'customer_reference',
        'customer_direction',
        'customer_enabled',
        'invoice_conflict',
        'invoice_reference',
        'invoice_direction',
        'invoice_enabled',
        'payment_conflict',
        'payment_reference',
        'payment_direction',
        'payment_enabled',
        'wallet_conflict',
        'wallet_reference',
        'wallet_direction',
        'wallet_enabled',
    ];

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
