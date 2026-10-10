<?php

return [
    'api' => [
        'version' => '0.0.2',
        'routing' => [
            'prefix'          => 'quickbooks',
            'internal_prefix' => 'int',
        ],
    ],
    'client_id'     => env('QUICKBOOKS_CLIENT_ID'),
    'client_secret' => env('QUICKBOOKS_CLIENT_SECRET'),
    'redirect_uri'  => env('QUICKBOOKS_REDIRECT_URI'),
    'environment'   => env('QUICKBOOKS_ENVIRONMENT', 'production'),
    'console_host'  => env('QUICKBOOKS_CONSOLE_HOST', env('CONSOLE_HOST')),
    'oauth'         => [
        // Requests per minute per client IP on the public OAuth callback. Behind a proxy the
        // host does not trust, all users share the proxy IP and this one limit. 0 turns it off.
        'callback_per_minute' => 30,
    ],
    'webhook'       => [
        // The oldest signed webhook delivery that is accepted, in seconds. A delivery whose
        // entity timestamps are older than this is rejected. The same number is how long a
        // delivered body is remembered to stop a replay, so the two checks stay consistent.
        // Raise it if Intuit retries a failed delivery after more than ten minutes.
        'max_age_seconds' => 600,
    ],
    'sync'          => [
        'enabled'                 => true,
        'interval_minutes'        => 5,
        'periodic_interval_hours' => 24,
        'batch_size'              => 100,
        'retry_limit'             => 5,
        'default_backoff_seconds' => 30,
        'customer_conflict'       => 'fleetbase',
        'customer_reference'      => 'fleetbase',
        'customer_direction'      => 'both',
        'invoice_conflict'        => 'fleetbase',
        'invoice_reference'       => 'fleetbase',
        'invoice_direction'       => 'both',
        'payment_conflict'        => 'fleetbase',
        'payment_reference'       => 'fleetbase',
        'payment_direction'       => 'both',
        'wallet_conflict'         => 'fleetbase',
        'wallet_reference'        => 'fleetbase',
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
