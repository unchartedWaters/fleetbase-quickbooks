<?php

namespace Fleetbase\Quickbooks\Tests\Support;

use Fleetbase\Quickbooks\Http\Controllers\ConnectionController;
use Fleetbase\Quickbooks\Services\ConnectionProbe;
use Fleetbase\Quickbooks\Services\OAuthFlow;
use Fleetbase\Quickbooks\Services\QuickBooksClient;
use Fleetbase\Quickbooks\Services\SettingsService;
use Fleetbase\Quickbooks\Support\Authorizer;
use Fleetbase\Quickbooks\Support\CredentialResolver;
use Fleetbase\Quickbooks\Support\SecretCipher;
use Fleetbase\Quickbooks\Support\SyncSettingsResolver;
use Illuminate\Database\Connection;
use Illuminate\Database\ConnectionResolver;
use Illuminate\Database\Eloquent\Model;

/**
 * A database for controller tests: every quickbooks_connections read returns one stored
 * connection row, other reads return nothing, and inserts are recorded, not run.
 */
class ControllerDatabase extends Connection
{
    /** @var array<int, string> */
    public array $inserts = [];

    /**
     * @param array<string, mixed>|null $connectionRow
     */
    public function __construct(public ?array $connectionRow)
    {
        parent::__construct(new class extends \PDO {
            public function __construct()
            {
            }
        }, 'testing', '', ['name' => 'testing']);
    }

    public function select($query, $bindings = [], $useReadPdo = true)
    {
        if ($this->connectionRow !== null && str_contains((string) $query, 'quickbooks_connections') === true) {
            return [$this->connectionRow];
        }

        return [];
    }

    public function insert($query, $bindings = [])
    {
        $this->inserts[] = (string) $query;

        return true;
    }

    /**
     * Runs the callback with this as every model's connection, then restores the previous one.
     */
    public function serve(callable $callback): mixed
    {
        $resolver = new ConnectionResolver(['testing' => $this]);
        $resolver->setDefaultConnection('testing');
        $previous = Model::getConnectionResolver();
        Model::setConnectionResolver($resolver);
        try {
            return $callback();
        } finally {
            if ($previous === null) {
                Model::unsetConnectionResolver();
            } else {
                Model::setConnectionResolver($previous);
            }
        }
    }

    /**
     * A connection row for company-uuid on realm-1.
     *
     * @return array<string, mixed>
     */
    public static function connected(bool $needsReauth = false): array
    {
        return [
            'uuid'         => 'conn-1',
            'company_uuid' => 'company-uuid',
            'realm_id'     => 'realm-1',
            'needs_reauth' => $needsReauth,
            'created_at'   => '2026-10-01 00:00:00',
            'updated_at'   => '2026-10-01 00:00:00',
        ];
    }

    /**
     * A connection controller that allows every permission.
     */
    public static function controller(): ConnectionController
    {
        return new ConnectionController(
            new Authorizer(static fn () => true),
            new OAuthFlow(new QuickBooksClient()),
            new SettingsService(new CredentialResolver(), new SyncSettingsResolver(), new SecretCipher()),
            new MemorySettingsStore(),
            new ConnectionProbe(new QuickBooksClient())
        );
    }
}
