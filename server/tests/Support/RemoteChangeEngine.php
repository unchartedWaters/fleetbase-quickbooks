<?php

namespace Fleetbase\Quickbooks\Tests\Support;

use Fleetbase\Quickbooks\Jobs\ApplyRemoteChange;
use Fleetbase\Quickbooks\Services\FleetbaseDirectory;
use Fleetbase\Quickbooks\Services\SettingsService;
use Fleetbase\Quickbooks\Services\SyncEngine;
use Fleetbase\Quickbooks\Services\SyncLedger;
use Fleetbase\Quickbooks\Support\BackoffPolicy;
use Fleetbase\Quickbooks\Support\CredentialResolver;
use Fleetbase\Quickbooks\Support\CustomerMapper;
use Fleetbase\Quickbooks\Support\InvoiceMapper;
use Fleetbase\Quickbooks\Support\QuickBooksException;
use Fleetbase\Quickbooks\Support\SecretCipher;
use Fleetbase\Quickbooks\Support\SyncSettingsResolver;
use Fleetbase\Quickbooks\Support\WalletMapper;
use Illuminate\Cache\ArrayStore;
use Illuminate\Cache\Repository;
use Illuminate\Support\Facades\Cache;

/**
 * An engine whose QuickBooks read for a remote change records the call and then fails
 * with the given exception, as a 429, a 5xx or a lost connection would.
 */
class RemoteChangeEngine extends SyncEngine
{
    /** @var array<int, array<int, array<string, string>>> */
    public array $calls = [];

    public ?QuickBooksException $failure = null;

    /** Runs as the QuickBooks read, with the company lock released. */
    public ?\Closure $duringRead = null;

    public static function failingWith(?QuickBooksException $failure): self
    {
        $engine          = new self(
            new FakeQuickBooks(),
            new CustomerMapper(),
            new InvoiceMapper(),
            new WalletMapper(),
            new BackoffPolicy(static fn (int $wait): int => $wait)
        );
        $engine->failure = $failure;

        return $engine;
    }

    public function acceptRemoteChanges(
        SyncLedger $ledger,
        string $companyUuid,
        array $entities,
        array $settings,
        int $now,
    ): void {
        $this->calls[] = $entities;
        if ($this->duringRead !== null) {
            $this->runHttp($this->duringRead);
        }
        if ($this->failure !== null) {
            throw $this->failure;
        }
    }

    /**
     * Runs the job with an array cache for the company lock and returns the jobs it queued.
     *
     * @return array<int, object>
     */
    public function run(ApplyRemoteChange $job, FleetbaseDirectory $directory): array
    {
        $cache    = Cache::getFacadeRoot();
        $settings = new SettingsService(new CredentialResolver(), new SyncSettingsResolver(), new SecretCipher());
        Cache::swap(new Repository(new ArrayStore()));
        try {
            $store = new MemorySettingsStore();

            return CapturedJobs::capture(fn () => $job->handle($this, $directory, $settings, $store));
        } finally {
            Cache::swap($cache);
        }
    }
}
