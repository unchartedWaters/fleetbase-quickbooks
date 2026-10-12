<?php

use Fleetbase\Quickbooks\Services\SettingsStore;
use Fleetbase\Quickbooks\Support\SafeLog;
use Fleetbase\Quickbooks\Support\SettingsKeys;
use Fleetbase\Quickbooks\Support\SyncSettingsResolver;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * The global sync row is now only a template. Every organization that is connected, or that already has
 * a sync row, gets its current effective settings stored as its own complete copy, so nothing changes for
 * it at upgrade and later global edits do not reach it. Only sync rows are read or written, never auth rows.
 */
return new class extends Migration {
    private const COMPANY_KEY = '/^company\.([^.]+)\.quickbooks\.sync$/';

    public function up(): void
    {
        if (Schema::hasTable('settings') === false) {
            return;
        }

        $admin    = $this->row(SettingsKeys::adminSync());
        $defaults = config('quickbooks.sync', []);
        $resolver = new SyncSettingsResolver();
        $defaults = is_array($defaults) === true ? $defaults : [];
        foreach (array_chunk($this->companies(), 200) as $chunk) {
            foreach ($chunk as $companyUuid) {
                $this->snapshot($companyUuid, $resolver, $admin, $defaults);
            }
        }
    }

    /**
     * Rows stay as they are on rollback: a copy is the organization's own data and the old build reads it as an override.
     */
    public function down(): void
    {
    }

    /**
     * @param array<string, mixed> $admin
     * @param array<string, mixed> $defaults
     */
    private function snapshot(string $companyUuid, SyncSettingsResolver $resolver, array $admin, array $defaults): void
    {
        try {
            $key = SettingsKeys::companySync($companyUuid);
            $row = $this->row($key);
            if (SettingsStore::isCompleteCopy($row) === true) {
                return;
            }
            $effective = SettingsStore::onlySyncFields($resolver->resolve(SettingsStore::syncFromRow($row), $admin, $defaults));
            $copy      = array_merge($effective, [SettingsKeys::ORGANIZATION_MARKER => SettingsKeys::ORGANIZATION_MARKER_VALUE]);
            DB::table('settings')->updateOrInsert(['key' => $key], ['value' => json_encode($copy)]);
        } catch (\Throwable $exception) {
            SafeLog::warning('QuickBooks organization sync snapshot failed.', [
                'company_uuid' => $companyUuid,
                'exception'    => $exception::class,
                'message'      => $exception->getMessage(),
            ]);
        }
    }

    /**
     * Organizations with a QuickBooks connection or an existing sync row.
     *
     * @return array<int, string>
     */
    private function companies(): array
    {
        $companies = [];
        if (Schema::hasTable('quickbooks_connections') === true) {
            $uuids = DB::table('quickbooks_connections')
                ->whereNotNull('realm_id')
                ->where('realm_id', '!=', '')
                ->whereNotNull('company_uuid')
                ->pluck('company_uuid');
            foreach ($uuids as $uuid) {
                $companies[trim((string) $uuid)] = true;
            }
        }
        $keys = DB::table('settings')->where('key', 'like', 'company.%.quickbooks.sync')->pluck('key');
        foreach ($keys as $key) {
            if (preg_match(self::COMPANY_KEY, (string) $key, $match) === 1) {
                $companies[$match[1]] = true;
            }
        }
        unset($companies['']);

        return array_map('strval', array_keys($companies));
    }

    /**
     * @return array<string, mixed>
     */
    private function row(string $key): array
    {
        $raw = DB::table('settings')->where('key', $key)->value('value');
        if (is_string($raw) === false) {
            return is_array($raw) === true ? $raw : [];
        }
        $value = json_decode($raw, true);
        if (is_string($value) === true) {
            $value = json_decode($value, true);
        }

        return is_array($value) === true ? $value : [];
    }
};
