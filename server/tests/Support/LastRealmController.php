<?php

namespace Fleetbase\Quickbooks\Tests\Support;

use Fleetbase\Quickbooks\Auth\Schemas\Quickbooks;
use Fleetbase\Quickbooks\Http\Controllers\ConnectionController;
use Fleetbase\Quickbooks\Services\ConnectionProbe;
use Fleetbase\Quickbooks\Services\OAuthFlow;
use Fleetbase\Quickbooks\Services\QuickBooksClient;
use Fleetbase\Quickbooks\Services\SettingsService;
use Fleetbase\Quickbooks\Services\SettingsStore;
use Fleetbase\Quickbooks\Support\Authorizer;
use Fleetbase\Quickbooks\Support\CredentialResolver;
use Fleetbase\Quickbooks\Support\SecretCipher;
use Fleetbase\Quickbooks\Support\SyncSettingsResolver;

/**
 * A connection controller whose last realm is set by the test and whose saved connection stays in memory.
 */
class LastRealmController extends ConnectionController
{
    public ?string $last = null;

    /** @var array<string, mixed>|null */
    public ?array $saved = null;

    /**
     * An installation administrator may do anything; otherwise the caller holds the QuickBooks Operator policy.
     */
    public static function make(bool $administrator, ?string $lastRealm, SettingsStore $store): self
    {
        $operator = (new Quickbooks())->policies[1]['permissions'];
        $checker  = $administrator === true
            ? static fn (): bool => true
            : static fn (string $permission): bool => in_array($permission, $operator, true);
        $controller = new self(
            new Authorizer($checker),
            new OAuthFlow(new QuickBooksClient()),
            new SettingsService(new CredentialResolver(), new SyncSettingsResolver(), new SecretCipher()),
            $store,
            new ConnectionProbe(new QuickBooksClient())
        );
        $controller->last = $lastRealm;

        return $controller;
    }

    protected function persist(array $connection): void
    {
        $this->saved = $connection;
    }

    protected function connectionIsStored(string $companyUuid): bool
    {
        return $this->saved !== null;
    }

    protected function lastRealm(string $companyUuid): ?string
    {
        return $this->last;
    }
}
