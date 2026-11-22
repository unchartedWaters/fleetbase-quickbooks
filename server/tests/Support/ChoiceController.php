<?php

namespace Fleetbase\Quickbooks\Tests\Support;

use Fleetbase\Quickbooks\Http\Controllers\ConnectionController;
use Fleetbase\Quickbooks\Services\CompanyReset;
use Fleetbase\Quickbooks\Services\ConnectionProbe;
use Fleetbase\Quickbooks\Services\OAuthFlow;
use Fleetbase\Quickbooks\Services\QuickBooksClient;
use Fleetbase\Quickbooks\Services\SettingsService;
use Fleetbase\Quickbooks\Services\SettingsStore;
use Fleetbase\Quickbooks\Support\Authorizer;
use Fleetbase\Quickbooks\Support\CredentialResolver;
use Fleetbase\Quickbooks\Support\SecretCipher;
use Fleetbase\Quickbooks\Support\SettingsKeys;
use Fleetbase\Quickbooks\Support\SyncSettingsResolver;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * A connection controller over the DataChoiceDatabase: customers are the test table,
 * and a saved connection is kept in memory.
 */
class ChoiceController extends ConnectionController
{
    /** @var array<string, mixed>|null */
    public ?array $saved = null;

    private SettingsStore $memory;

    /**
     * @param bool $mayChangeCompany whether the caller holds the change-company permission
     */
    public static function make(SettingsStore $store, bool $mayChangeCompany = true): self
    {
        $store->rows[SettingsKeys::adminAuth()] = [
            'client_id'     => 'client-id',
            'client_secret' => (new SecretCipher())->encrypt('client-secret'),
            'environment'   => 'sandbox',
        ];

        $controller = new self(
            new Authorizer(static fn (string $permission): bool => $mayChangeCompany === true
                || $permission !== 'quickbooks change-company connection'),
            new OAuthFlow(new QuickBooksClient()),
            new SettingsService(new CredentialResolver(), new SyncSettingsResolver(), new SecretCipher()),
            $store,
            new ConnectionProbe(new QuickBooksClient()),
            new QuickBooksClient()
        );
        $controller->memory = $store;

        return $controller;
    }

    /**
     * Disconnect with the given answer, as the console posts it.
     */
    public function disconnectWith(string $level, string $code): JsonResponse
    {
        return $this->disconnect(Request::create('/disconnect', 'POST', ['level' => $level, 'confirm' => $code]));
    }

    public function leaves(string $companyUuid, string $realmId): bool
    {
        return $this->leavesLinksBehind($companyUuid, $realmId);
    }

    protected function reset(): CompanyReset
    {
        return new CompanyReset($this->memory, TestCustomer::class, TestPlace::class);
    }

    protected function persist(array $connection): void
    {
        $this->saved = $connection;
    }
}
