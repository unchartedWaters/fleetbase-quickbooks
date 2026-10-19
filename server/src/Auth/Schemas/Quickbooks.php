<?php

namespace Fleetbase\Quickbooks\Auth\Schemas;

class Quickbooks
{
    /**
     * Permission prefix. create-permissions turns this into "quickbooks see extension" and "quickbooks *".
     */
    public string $name = 'quickbooks';

    /**
     * Produces QuickbooksFullAccess and QuickbooksReadOnly.
     */
    public string $policyName = 'Quickbooks';

    /**
     * @var array<int, string>
     */
    public array $guards = ['sanctum'];

    /**
     * @var array<int, array<string, mixed>>
     */
    public array $resources = [
        [
            // change-company: connect a new or different QuickBooks company (Administrator only).
            'name'    => 'connection',
            'actions' => ['connect', 'disconnect', 'import-customers', 'change-company'],
        ],
        [
            // "view" and "update" cover an organization's own sync settings. The global settings (Intuit
            // credentials, environment, webhook verifier, sync template) are saved on the Admin
            // screen and are checked as installation administrator, not as a permission.
            'name'           => 'settings',
            'actions'        => [],
            'remove_actions' => ['create', 'delete', 'list'],
        ],
        [
            'name'           => 'sync',
            'actions'        => ['reconcile'],
            'remove_actions' => ['create', 'delete'],
        ],
    ];

    /**
     * @var array<int, array<string, mixed>>
     */
    public array $policies = [
        [
            'name'        => 'QuickbooksAdministrator',
            'description' => 'Full access to the QuickBooks connection, settings, and sync.',
            'permissions' => [
                'quickbooks see extension',
                'quickbooks * connection',
                'quickbooks * settings',
                'quickbooks * sync',
            ],
        ],
        [
            'name'        => 'QuickbooksOperator',
            'description' => 'Reconnect the QuickBooks company already in use, import customers, view settings, '
                . 'and run Sync now.',
            'permissions' => [
                'quickbooks see extension',
                'quickbooks view connection',
                'quickbooks connect connection',
                'quickbooks disconnect connection',
                'quickbooks import-customers connection',
                'quickbooks view settings',
                'quickbooks view sync',
                // Sync now and reconcile both authorize as this permission.
                'quickbooks reconcile sync',
            ],
        ],
    ];

    /**
     * @var array<int, array<string, mixed>>
     */
    public array $roles = [
        [
            'name'        => 'QuickBooks Administrator',
            'description' => 'Administers the QuickBooks connection for an organization.',
            'policies'    => ['QuickbooksAdministrator'],
        ],
        [
            'name'        => 'QuickBooks Operator',
            'description' => 'Reconnects the QuickBooks company the organization already uses, disconnects, '
                . 'imports customers, views settings and sync activity, and runs Sync now. Cannot change '
                . 'settings or connect a different QuickBooks company.',
            'policies'    => ['QuickbooksOperator'],
        ],
    ];
}
