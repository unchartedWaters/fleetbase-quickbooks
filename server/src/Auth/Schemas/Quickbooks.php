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
            'name'    => 'connection',
            'actions' => ['connect', 'disconnect', 'import-customers'],
        ],
        [
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
            'description' => 'Connect QuickBooks, import customers, update settings, and run Sync now.',
            'permissions' => [
                'quickbooks see extension',
                'quickbooks view connection',
                'quickbooks connect connection',
                'quickbooks disconnect connection',
                'quickbooks import-customers connection',
                'quickbooks view settings',
                'quickbooks update settings',
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
            'description' => 'Runs the QuickBooks connection and Sync now.',
            'policies'    => ['QuickbooksOperator'],
        ],
    ];
}
