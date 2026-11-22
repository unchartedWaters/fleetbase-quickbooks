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
     * Sync options an organization holds its own copy of. Credentials, the webhook verifier and the
     * public URLs are global and are never stored on an organization row.
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
        'tax_source',
    ];

    /**
     * Sync fields added after organizations had copies, with the value an older copy keeps: the
     * behaviour from before the field existed, never the template's.
     *
     * @var array<string, string>
     */
    public const LATER_SYNC_FIELDS = [
        'tax_source' => 'quickbooks',
    ];

    public static function companyAuth(string $companyUuid): string
    {
        return 'company.' . $companyUuid . '.quickbooks.auth';
    }

    /**
     * The QuickBooks company (realm) an organization last connected. It outlives a disconnect,
     * so an Operator can reconnect that company but not a different one.
     */
    public static function companyLastRealm(string $companyUuid): string
    {
        return 'company.' . $companyUuid . '.quickbooks.last_realm';
    }

    public static function adminAuth(): string
    {
        return 'system.quickbooks.auth';
    }

    /**
     * Intuit issues a separate set of keys for its Development (sandbox) and Production apps. The admin row holds
     * one set per environment, each field prefixed with its environment (production_client_id, sandbox_client_id).
     *
     * @var array<int, string>
     */
    public const ENVIRONMENTS = ['production', 'sandbox'];

    /** @var array<int, string> */
    public const CREDENTIAL_FIELDS = ['client_id', 'client_secret', 'webhook_verifier'];

    public static function credentialField(string $environment, string $field): string
    {
        return $environment . '_' . $field;
    }

    /**
     * Intuit's two environments. Anything that is not production is sandbox, as the API client reads it.
     */
    public static function environment(?string $environment): string
    {
        return $environment === 'production' ? 'production' : 'sandbox';
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
