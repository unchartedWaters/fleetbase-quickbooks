<?php

namespace Fleetbase\Quickbooks\Services;

use Fleetbase\Quickbooks\Models\Connection;
use Fleetbase\Quickbooks\Models\Link;
use Fleetbase\Quickbooks\Models\PendingSync;
use Fleetbase\Quickbooks\Models\SyncAttempt;
use Fleetbase\Quickbooks\Models\SyncBatch;
use Fleetbase\Quickbooks\Support\SettingsKeys;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

/**
 * What an organization's QuickBooks data is made of, and clearing it at one of three levels:
 * sync data (links, queued work, history), plus the organization's sync settings, or plus the
 * Fleetbase customers that were imported from QuickBooks and nothing else refers to. Other Fleetbase records
 * are never touched.
 */
class CompanyReset
{
    public const SYNC = 'sync';

    public const SETTINGS = 'settings';

    public const EVERYTHING = 'everything';

    /** Disconnect only: leave the links so a reconnect to the same company picks them up. */
    public const KEEP = 'keep';

    /** Letters and digits that are hard to mistake for each other, for the code a user types to confirm. */
    private const CODE_ALPHABET = 'ABCDEFGHJKLMNPQRSTUVWXYZ23456789';

    private const CODE_LENGTH = 6;

    private const CODE_MINUTES = 10;

    /** Key in a customer's meta that marks it as created by a QuickBooks import. */
    public const IMPORT_META = 'quickbooks_import';

    private const CUSTOMER_CLASS = 'Fleetbase\\FleetOps\\Models\\Customer';

    private const PLACE_CLASS = 'Fleetbase\\FleetOps\\Models\\Place';

    /** Tables whose rows refer to a customer by uuid. A customer one of them refers to is kept. */
    private const USED_BY = ['orders' => 'customer_uuid', 'ledger_invoices' => 'customer_uuid'];

    /**
     * @param string                $customerClass the Fleetbase customer model
     * @param string                $placeClass    the Fleetbase place model, which holds billing addresses
     * @param array<string, string> $usedBy        table => column of the records that refer to a customer
     */
    public function __construct(
        private SettingsStore $store,
        private string $customerClass = self::CUSTOMER_CLASS,
        private string $placeClass = self::PLACE_CLASS,
        private array $usedBy = self::USED_BY,
    ) {
    }

    /**
     * The marker saved in the meta of a customer imported from QuickBooks.
     *
     * @return array<string, array<string, string>>
     */
    public static function importMarker(string $realm, string $remoteId): array
    {
        return [self::IMPORT_META => ['source' => 'quickbooks', 'realm_id' => $realm, 'qbo_id' => $remoteId]];
    }

    public static function isLevel(string $level): bool
    {
        return in_array($level, [self::SYNC, self::SETTINGS, self::EVERYTHING], true);
    }

    /**
     * A random code for this user and organization to type before data is cleared. A script that
     * does not read it first cannot confirm, so a wipe is never one click. A new code replaces the last.
     */
    public function issueCode(string $companyUuid, string $userUuid): string
    {
        $code = '';
        for ($i = 0; $i < self::CODE_LENGTH; $i++) {
            $code .= self::CODE_ALPHABET[random_int(0, strlen(self::CODE_ALPHABET) - 1)];
        }
        Cache::put($this->codeKey($companyUuid, $userUuid), $code, self::CODE_MINUTES * 60);

        return $code;
    }

    /**
     * Whether $typed is the code issued to this user and organization. A right answer uses the code up.
     */
    public function consumeCode(string $companyUuid, string $userUuid, string $typed): bool
    {
        $issued = Cache::get($this->codeKey($companyUuid, $userUuid));
        if (is_string($issued) === false || $issued === '' || $userUuid === '') {
            return false;
        }
        if (hash_equals($issued, strtoupper(trim($typed))) === false) {
            return false;
        }
        Cache::forget($this->codeKey($companyUuid, $userUuid));

        return true;
    }

    private function codeKey(string $companyUuid, string $userUuid): string
    {
        return 'quickbooks.reset-code.' . $companyUuid . '.' . $userUuid;
    }

    /**
     * How many Fleetbase records are linked to QuickBooks records, by kind, all links together, and
     * how many imported Fleetbase customers a wipe would delete and keep. The imported counts scan the
     * customers, so they are only worked out when $withImported is set.
     *
     * @return array{customers: int, invoices: int, wallets: int, products: int, total: int, imported: int, kept: int}
     */
    public function linkCounts(string $companyUuid, bool $withImported = false): array
    {
        $byType = Link::query()
            ->where('company_uuid', $companyUuid)
            ->selectRaw('local_type, count(*) as total')
            ->groupBy('local_type')
            ->pluck('total', 'local_type')
            ->all();
        $total = 0;
        foreach ($byType as $count) {
            $total += (int) $count;
        }
        $countOf = static fn (string $type): int => (int) ($byType[$type] ?? 0);

        $imported = $withImported === true ? $this->importedCustomers($companyUuid, true)?->count() ?? 0 : 0;
        $marked   = $withImported === true ? $this->importedCustomers($companyUuid)?->count() ?? 0 : 0;

        return [
            'customers' => $countOf('customer'),
            'invoices'  => $countOf('invoice'),
            'wallets'   => $countOf('wallet'),
            'products'  => $countOf('item'),
            'total'     => $total,
            'imported'  => $imported,
            'kept'      => $marked - $imported,
        ];
    }

    /**
     * Links that belong to another QuickBooks company than $realm: what a switch to $realm leaves behind.
     */
    public function linksOutsideRealm(string $companyUuid, string $realm): int
    {
        return Link::query()->where('company_uuid', $companyUuid)->where('realm_id', '!=', $realm)->count();
    }

    /**
     * Clear this organization's QuickBooks data at $level. With $keepConnection the connection row stays, for
     * a switch that is about to replace it; otherwise it is removed too, and the company it last used is forgotten.
     */
    public function clear(string $companyUuid, string $level, bool $keepConnection): void
    {
        // One transaction, so a failure part-way leaves everything as it was.
        DB::transaction(function () use ($companyUuid, $level, $keepConnection): void {
            if ($level === self::EVERYTHING) {
                $this->deleteImportedCustomers($companyUuid);
            }
            Link::query()->where('company_uuid', $companyUuid)->delete();
            PendingSync::query()->where('company_uuid', $companyUuid)->delete();
            SyncAttempt::query()->where('company_uuid', $companyUuid)->delete();
            SyncBatch::query()->where('company_uuid', $companyUuid)->delete();
            if ($keepConnection === false) {
                Connection::query()->where('company_uuid', $companyUuid)->delete();
                $this->store->put(SettingsKeys::companyLastRealm($companyUuid), []);
            }
            if ($level !== self::SYNC) {
                // No saved copy shows the global defaults again, and connecting makes a fresh copy.
                $this->store->put(SettingsKeys::companySync($companyUuid), []);
            }
        });
    }

    /**
     * Customers created by a QuickBooks import, found by their marker. Null without Fleet-Ops. With $deletable,
     * the ones an order or an invoice refers to are left out: deleting them would blank those records.
     */
    private function importedCustomers(string $companyUuid, bool $deletable = false): ?Builder
    {
        $class = $this->customerClass;
        if (class_exists($class) === false) {
            return null;
        }

        $query = $class::query()
            ->where('company_uuid', $companyUuid)
            ->where('meta->' . self::IMPORT_META . '->source', 'quickbooks');
        if ($deletable === false) {
            return $query;
        }
        /** @var Model $model */
        $model      = new $class();
        $schema     = $model->getConnection()->getSchemaBuilder();
        $ownedTable = $model->getTable();
        foreach ($this->usedBy as $table => $column) {
            if ($schema->hasTable($table) === false) {
                continue;
            }
            $query->whereNotExists(static function ($used) use ($table, $column, $ownedTable): void {
                $used->selectRaw('1')->from($table)->whereColumn($table . '.' . $column, $ownedTable . '.uuid');
            });
        }

        return $query;
    }

    /**
     * Delete the imported customers nothing refers to, and the billing places made for them.
     * The delete is a query, so no model event is raised and nothing is sent to QuickBooks.
     */
    private function deleteImportedCustomers(string $companyUuid): void
    {
        $customers = $this->importedCustomers($companyUuid, true);
        if ($customers === null) {
            return;
        }
        $place = $this->placeClass;
        $class = $this->customerClass;
        foreach (array_chunk($customers->pluck('uuid')->all(), 200) as $chunk) {
            if (class_exists($place) === true) {
                $place::query()->whereIn('owner_uuid', $chunk)->delete();
            }
            $class::query()->where('company_uuid', $companyUuid)->whereIn('uuid', $chunk)->delete();
        }
    }
}
