<?php

namespace Fleetbase\Quickbooks\Console\Commands;

use Fleetbase\Quickbooks\Jobs\SyncCompanyBatch;
use Fleetbase\Quickbooks\Models\Connection;
use Fleetbase\Quickbooks\Models\PendingSync;
use Fleetbase\Quickbooks\Services\BatchRunner;
use Fleetbase\Quickbooks\Support\SyncSchedule;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;

class SyncQuickbooks extends Command
{
    protected $signature = 'quickbooks:sync';

    protected $description = 'Queue a QuickBooks batch for each organization that is due for a sync.';

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

        foreach ($companies as $companyUuid) {
            if ($runner->hasConnection($companyUuid) === false) {
                continue;
            }
            if ($runner->isScheduledDue($companyUuid, $now) === false) {
                continue;
            }
            SyncCompanyBatch::dispatch($companyUuid);
            $queued++;
        }

        if ($queued > 0) {
            // Keep the next minute open so due work, including a 20-due early start, can drain.
            SyncSchedule::defer($now, 60);
        } else {
            SyncSchedule::defer($now, $this->idleSeconds($runner, $companies));
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
        $connected = [];
        $rows      = Connection::query()
            ->where('needs_reauth', false)
            ->whereNotNull('realm_id')
            ->where('realm_id', '!=', '')
            ->get(['company_uuid', 'realm_id']);
        foreach ($rows as $row) {
            $companyUuid = trim((string) $row->company_uuid);
            $realm       = trim((string) $row->realm_id);
            if ($companyUuid === '' || $realm === '') {
                continue;
            }
            $connected[] = $companyUuid;
        }

        $due = count($connected) === 1 ? $this->dueCompanies() : [];

        return self::companiesToSchedule($connected, $due);
    }

    /**
     * One shared connection schedules every organization with due pending rows
     * for that realm. Several connections schedule only the organization on each row.
     *
     * @param array<int, string> $connected
     * @param array<int, string> $dueCompanies
     *
     * @return array<int, string>
     */
    public static function companiesToSchedule(array $connected, array $dueCompanies): array
    {
        $owners = [];
        foreach ($connected as $companyUuid) {
            $companyUuid = trim((string) $companyUuid);
            if ($companyUuid !== '') {
                $owners[$companyUuid] = true;
            }
        }
        if ($owners === []) {
            return [];
        }
        if (count($owners) !== 1) {
            return array_keys($owners);
        }

        foreach ($dueCompanies as $companyUuid) {
            $companyUuid = trim((string) $companyUuid);
            if ($companyUuid !== '') {
                $owners[$companyUuid] = true;
            }
        }

        return array_keys($owners);
    }

    /**
     * Organizations with a pending row that is due now.
     *
     * @return array<int, string>
     */
    private function dueCompanies(): array
    {
        $ids       = [];
        $now       = Carbon::now();
        $companies = PendingSync::query()
            ->where('status', 'pending')
            ->where(function ($query) use ($now): void {
                $query->whereNull('next_attempt_at')->orWhere('next_attempt_at', '<=', $now);
            })
            ->distinct()
            ->pluck('company_uuid');
        foreach ($companies as $companyUuid) {
            $companyUuid = trim((string) $companyUuid);
            if ($companyUuid !== '') {
                $ids[] = $companyUuid;
            }
        }

        return $ids;
    }

    /**
     * Soonest enabled sync interval among the connections already considered.
     * No QuickBooks write is used to find it. 300 matches the config default.
     *
     * @param iterable<mixed> $companies
     */
    private function idleSeconds(BatchRunner $runner, iterable $companies): int
    {
        $wait = null;
        foreach ($companies as $companyUuid) {
            $seconds = $runner->deferSeconds((string) $companyUuid);
            if ($seconds < 1) {
                continue;
            }
            $wait = $wait === null ? $seconds : min($wait, $seconds);
        }

        return $wait ?? 300;
    }
}
