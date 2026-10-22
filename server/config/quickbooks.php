<?php

return [
    'api' => [
        'routing' => [
            'prefix'          => 'quickbooks',
            'internal_prefix' => 'int',
        ],
    ],
    'environment'   => env('QUICKBOOKS_ENVIRONMENT', 'production'),
    'console_host'  => env('QUICKBOOKS_CONSOLE_HOST', env('CONSOLE_HOST')),
    'oauth'         => [
        // Requests per minute per client IP on the public OAuth callback. Behind a proxy the
        // host does not trust, all users share the proxy IP and this one limit. 0 turns it off.
        'callback_per_minute' => 30,
    ],
    'webhook'       => [
        // The oldest signed webhook delivery that is applied in full, in seconds. A delivery
        // whose entity timestamps are older than this is stale (see stale_replay_seconds).
        'max_age_seconds' => 600,
        // A signed delivery older than max_age_seconds but inside this window (7 days) is
        // still used, only to read the current record from QuickBooks: its creates and
        // updates are applied, its deletes and merges are not. Every processed body is
        // remembered for this long (at least max_age_seconds) so it is not used twice. A
        // delivery older than this is not applied. A value no larger than max_age_seconds
        // (for example 0) turns stale processing off.
        'stale_replay_seconds' => 604800,
    ],
    'sync'          => [
        'enabled'                 => true,
        'interval_minutes'        => 5,
        'periodic_interval_hours' => 24,
        'batch_size'              => 100,
        'retry_limit'             => 5,
        'default_backoff_seconds' => 30,
        'customer_conflict'       => 'quickbooks',
        'customer_reference'      => 'quickbooks',
        'customer_direction'      => 'both',
        'invoice_conflict'        => 'quickbooks',
        'invoice_reference'       => 'quickbooks',
        'invoice_direction'       => 'both',
        'payment_conflict'        => 'quickbooks',
        'payment_reference'       => 'quickbooks',
        'payment_direction'       => 'both',
        'wallet_conflict'         => 'quickbooks',
        'wallet_reference'        => 'quickbooks',
        'wallet_direction'        => 'both',
        'customer_enabled'        => true,
        'invoice_enabled'         => true,
        'payment_enabled'         => true,
        // Off for a fresh install: a wallet per driver or customer can flood the QuickBooks chart of accounts.
        'wallet_enabled'          => false,
        // How long a running sync leases the pending rows it loaded. A lease left by a
        // killed worker expires after this many seconds. Keep it above the job timeout.
        'claim_seconds'           => 900,
    ],
    // Days to keep sync history. quickbooks:prune runs daily; 0 keeps that history forever.
    'retention' => [
        'attempt_days' => 90,
        'batch_days'   => 180,
        'pending_days' => 30,
    ],
];
