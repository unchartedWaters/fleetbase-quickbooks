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
    ],
    // Days to keep sync history. quickbooks:prune runs daily; 0 keeps that history forever.
    'retention' => [
        'attempt_days' => 90,
        'batch_days'   => 180,
        'pending_days' => 30,
    ],
];
