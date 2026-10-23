<?php

namespace Fleetbase\Quickbooks\Console\Commands;

use Fleetbase\Quickbooks\Jobs\SyncCompanyBatch;
use Fleetbase\Quickbooks\Models\Connection;
use Fleetbase\Quickbooks\Services\BatchRunner;
use Fleetbase\Quickbooks\Services\FleetbaseDirectory;
use Fleetbase\Quickbooks\Support\SyncSchedule;
use Illuminate\Console\Command;

class SyncQuickbooks extends Command
{
    /** The connection columns the schedule checks. */
    private const SCHEDULE_COLUMNS = [
        'company_uuid', 'realm_id', 'needs_reauth', 'token_expires_at', 'rate_limited_until', 'last_batch_at',
    ];

    protected $signature = 'quickbooks:sync';

    protected $description = 'Queue a QuickBooks batch for each organization that is due for a sync.';

    /**
     * The connections connectedCompanies() read this tick, by organization. Only the columns
     * the schedule checks are read; tokens stay in the database.
     *
     * @var array<string, array<string, mixed>>
     */
    private array $connections = [];

    public function handle(BatchRunner $runner): int
    {
        $queued    = 0;
        $now       = time();
        $companies = $this->connectedCompanies();
        // Connection is the gate. A stored sync.enabled flag is not read.
        // Saved credentials are not read. Stop before any log, defer, or queue.
        if ($companies === []) {
            return self::SUCCESS;
        }

        // Each organization's connection and settings, and the installation defaults, are read once.
        $adminSync = $runner->adminSync();
        $intervals = [];
        foreach ($companies as $companyUuid) {
            $settings                = $runner->scheduleSettings($companyUuid, $adminSync);
            $intervals[$companyUuid] = BatchRunner::intervalSeconds($settings);
            $connection              = $this->connections[$companyUuid] ?? $runner->connectionOf($companyUuid);
            if ($runner->isScheduledDueFor($companyUuid, $connection, $settings, $now) === false) {
                continue;
            }
            SyncCompanyBatch::dispatch($companyUuid);
            $queued++;
        }

        if ($queued > 0) {
            // Keep the next minute open so due work, including a 20-due early start, can drain.
            SyncSchedule::defer($now, 60);
        } else {
            SyncSchedule::defer($now, $this->idleSeconds($intervals));
        }

        $this->info('Queued QuickBooks sync for ' . $queued . ' organizations.');

        return self::SUCCESS;
    }

    /**
     * Organizations with a QuickBooks company id that can be synced.
     * sync.enabled is not read.
     *
     * @return array<int, string>
     */
    protected function connectedCompanies(): array
    {
        $connected         = [];
        $this->connections = [];
        $rows              = Connection::query()
            ->where('needs_reauth', false)
            ->whereNotNull('realm_id')
            ->where('realm_id', '!=', '')
            ->get(self::SCHEDULE_COLUMNS);
        foreach ($rows as $row) {
            if ($row instanceof Connection === false) {
                continue;
            }
            $companyUuid = trim((string) $row->company_uuid);
            $realm       = trim((string) $row->realm_id);
            if ($companyUuid === '' || $realm === '') {
                continue;
            }
            $connected[]                     = $companyUuid;
            $this->connections[$companyUuid] = FleetbaseDirectory::connectionToArray($row);
        }

        return self::companiesToSchedule($connected);
    }

    /**
     * Schedule the organization that owns each connection row.
     * A pending row for another organization does not borrow that connection.
     *
     * @param array<int, string> $connected
     * @param array<int, string> $dueCompanies
     *
     * @return array<int, string>
     */
    public static function companiesToSchedule(array $connected, array $dueCompanies = []): array
    {
        $owners = [];
        foreach ($connected as $companyUuid) {
            $companyUuid = trim((string) $companyUuid);
            if ($companyUuid !== '') {
                $owners[$companyUuid] = true;
            }
        }
        // Pending rows do not add an organization that does not own a connection.
        foreach ($dueCompanies as $companyUuid) {
            $companyUuid = trim((string) $companyUuid);
            if ($companyUuid !== '' && isset($owners[$companyUuid]) === true) {
                $owners[$companyUuid] = true;
            }
        }

        return array_keys($owners);
    }

    /**
     * Soonest enabled sync interval among the connections already considered.
     * No QuickBooks write is used to find it. 300 matches the config default.
     *
     * @param array<string, int> $intervals
     */
    private function idleSeconds(array $intervals): int
    {
        $wait = null;
        foreach ($intervals as $seconds) {
            if ($seconds < 1) {
                continue;
            }
            $wait = $wait === null ? $seconds : min($wait, $seconds);
        }

        return $wait ?? 300;
    }
}
