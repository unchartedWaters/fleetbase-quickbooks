<?php

namespace Fleetbase\Quickbooks\Services;

use Fleetbase\Quickbooks\Models\Connection;
use Fleetbase\Quickbooks\Support\CustomerMapper;
use Fleetbase\Quickbooks\Support\SafeLog;
use Fleetbase\Quickbooks\Support\SyncSuppressor;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class CustomerImporter
{
    public const CURSOR_COLUMN = 'customer_import_start';

    public const MAX_PAGE_SIZE = 100;

    /** @var callable|null */
    private $creator;

    /**
     * Adds the stored links a page needs to the ledger:
     * function(SyncLedger $ledger, array $qboIds, array $localUuids): void.
     */
    private ?\Closure $linkLoader = null;

    /**
     * The Fleetbase customers import() was given. Loading links rebuilds the ledger index,
     * so they are remembered again after each load.
     *
     * @var array<int, mixed>
     */
    private array $givenCustomers = [];

    /**
     * Fleetbase customers the last import() or importIds() created, by uuid.
     *
     * @var array<string, true>
     */
    private array $created = [];

    /**
     * Customer identity rows for one import(), keyed by company. Reset when import() starts.
     *
     * @var array{company: string, rows: array<int, object>}|null
     */
    private ?array $companyCandidates = null;

    /**
     * The resume position import() reached and storeSavedCursor() has not stored yet.
     *
     * @var array{company: string, start: int|null}|null
     */
    private ?array $unsavedCursor = null;

    /**
     * @param callable|null $creator function(array $connection, array $remote): array
     */
    public function __construct(private QuickBooksClient $client, ?callable $creator = null)
    {
        $this->creator = $creator;
    }

    /**
     * Read the stored links of each page's QuickBooks customers and matching Fleetbase
     * customers before matching, so customers already linked are not linked or created again.
     */
    public function useLinkLoader(?\Closure $loader): void
    {
        $this->linkLoader = $loader;
    }

    /**
     * @param array<string, mixed>             $connection
     * @param array<int, array<string, mixed>> $fleetbaseCustomers
     *
     * @return array<string, mixed>
     */
    public function import(SyncLedger $ledger, array $connection, array $fleetbaseCustomers, bool $enabled, int $pageSize = self::MAX_PAGE_SIZE, ?int $deadline = null): array
    {
        $batch = [
            'company_uuid' => $connection['company_uuid'],
            'trigger'      => 'import',
            'direction'    => 'inbound',
            'status'       => 'finished',
            'created'      => 0,
            'linked'       => 0,
            'skipped'      => 0,
            'failed'       => 0,
            'continue'     => false,
            'finished_at'  => time(),
        ];

        if ($enabled === false) {
            $ledger->batches[] = $batch;

            return $batch;
        }

        $pageSize = max(1, min(self::MAX_PAGE_SIZE, $pageSize));
        $this->startRun($fleetbaseCustomers);
        $ledger->ensureIndex();
        $this->rememberFleetbaseCustomers($ledger, $fleetbaseCustomers);

        $start = $this->cursor($connection);
        while (true) {
            $page = $this->client->queryCustomers($connection, $start, $pageSize);
            if ($this->rememberCandidates($ledger, $connection, $page) === false) {
                $this->failPage($ledger, $connection, $page, $batch);
                break;
            }
            $next = $this->importFetchedPage($ledger, $connection, $page, $batch, $fleetbaseCustomers, $start, $pageSize, $deadline);
            if ($next === null) {
                break;
            }
            $start = $next;
        }

        $batch['finished_at'] = time();
        $ledger->batches[]    = $batch;

        return $batch;
    }

    /**
     * Import the QuickBooks customers a webhook named, for example one just created in
     * QuickBooks. Customers already linked are not read again, and when none is left nothing
     * is read or recorded. The catalog cursor is left alone.
     *
     * @param array<string, mixed> $connection
     * @param array<int, string>   $ids
     *
     * @return array<string, mixed>
     */
    public function importIds(SyncLedger $ledger, array $connection, array $ids): array
    {
        $realm = (string) ($connection['realm_id'] ?? '');
        $ledger->ensureIndex();
        $wanted = [];
        foreach (array_unique(array_map('strval', $ids)) as $id) {
            if ($id !== '' && $ledger->linkForRemote($realm, 'Customer', $id) === null) {
                $wanted[] = $id;
            }
        }
        $batch = [
            'company_uuid' => $connection['company_uuid'],
            'trigger'      => 'import',
            'direction'    => 'inbound',
            'status'       => 'finished',
            'created'      => 0,
            'linked'       => 0,
            'skipped'      => 0,
            'failed'       => 0,
            'continue'     => false,
            'finished_at'  => time(),
        ];
        if ($wanted === []) {
            return $batch;
        }

        $this->startRun([]);
        $page = [];
        foreach ($wanted as $id) {
            $remote = $this->client->getCustomer($connection, $id);
            if (is_array($remote) === true) {
                $page[] = $remote;
            }
        }
        if ($page === []) {
            return $batch;
        }
        $fleetbaseCustomers = [];
        if ($this->rememberCandidates($ledger, $connection, $page) === false) {
            $this->failPage($ledger, $connection, $page, $batch);
        } else {
            $this->tallyImportedPage($ledger, $connection, $page, $batch, $fleetbaseCustomers);
        }
        $batch['finished_at'] = time();
        $ledger->batches[]    = $batch;

        return $batch;
    }

    /**
     * @param array<int, mixed> $fleetbaseCustomers
     */
    private function startRun(array $fleetbaseCustomers): void
    {
        $this->companyCandidates = null;
        $this->unsavedCursor     = null;
        $this->givenCustomers    = $fleetbaseCustomers;
        $this->created           = [];
    }

    /**
     * Whether the last import() or importIds() created this Fleetbase customer.
     */
    public function createdCustomer(string $uuid): bool
    {
        return isset($this->created[$uuid]);
    }

    /**
     * @param array<int, mixed> $fleetbaseCustomers
     */
    private function rememberFleetbaseCustomers(SyncLedger $ledger, array $fleetbaseCustomers): void
    {
        foreach ($fleetbaseCustomers as $customer) {
            if (is_array($customer) === true && (string) ($customer['uuid'] ?? '') !== '') {
                $ledger->rememberCustomer((string) $customer['uuid'], $customer);
            }
        }
    }

    /**
     * Records one fetched page and returns the next start position, or null when the import should stop.
     *
     * @param array<string, mixed>             $connection
     * @param array<int, mixed>                $page
     * @param array<string, mixed>             $batch
     * @param array<int, array<string, mixed>> $fleetbaseCustomers
     */
    private function importFetchedPage(SyncLedger $ledger, array &$connection, array $page, array &$batch, array &$fleetbaseCustomers, int $start, int $pageSize, ?int $deadline): ?int
    {
        $failedAt = $this->tallyImportedPage($ledger, $connection, $page, $batch, $fleetbaseCustomers);
        if ($failedAt !== null) {
            $next = $start + $failedAt;
            if ($next > 1) {
                $this->writeCursor($ledger, $connection, $next);
            }

            return null;
        }
        if (count($page) < $pageSize) {
            $this->writeCursor($ledger, $connection, 1);

            return null;
        }

        $start += $pageSize;
        $this->writeCursor($ledger, $connection, $start);
        if ($deadline !== null && time() >= $deadline) {
            $batch['continue'] = true;

            return null;
        }

        return $start;
    }

    /**
     * @param array<string, mixed>             $connection
     * @param array<int, mixed>                $page
     * @param array<string, mixed>             $batch
     * @param array<int, array<string, mixed>> $fleetbaseCustomers
     */
    private function tallyImportedPage(SyncLedger $ledger, array $connection, array $page, array &$batch, array &$fleetbaseCustomers): ?int
    {
        $failedAt = null;
        $position = 0;
        foreach ($page as $remote) {
            if (is_array($remote) === false) {
                $position++;
                continue;
            }
            $outcome         = $this->importOne($ledger, $connection, $fleetbaseCustomers, $remote);
            $batch[$outcome] = ($batch[$outcome] ?? 0) + 1;
            if ($outcome === 'failed' && $failedAt === null) {
                $failedAt = $position;
            }
            $position++;
        }

        return $failedAt;
    }

    /**
     * @param array<string, mixed> $connection
     */
    private function cursor(array $connection): int
    {
        $start = (int) ($connection[self::CURSOR_COLUMN] ?? 1);

        return $start > 1 ? $start : 1;
    }

    /**
     * @param array<string, mixed> $connection
     */
    private function writeCursor(SyncLedger $ledger, array &$connection, int $start): void
    {
        $companyUuid = (string) ($connection['company_uuid'] ?? '');
        if ($start <= 1) {
            unset($connection[self::CURSOR_COLUMN]);
            if ($companyUuid !== '' && isset($ledger->connections[$companyUuid]) === true) {
                unset($ledger->connections[$companyUuid][self::CURSOR_COLUMN]);
            }
            $stored = null;
        } else {
            $connection[self::CURSOR_COLUMN] = $start;
            if ($companyUuid !== '') {
                $ledger->connections[$companyUuid][self::CURSOR_COLUMN] = $start;
            }
            $stored = $start;
        }

        // Kept in memory until the customers of these pages are saved; see storeSavedCursor().
        if ($companyUuid !== '') {
            $this->unsavedCursor = ['company' => $companyUuid, 'start' => $stored];
        }
    }

    /**
     * Store the resume position the last import() reached. Call it only after the ledger that
     * holds the imported customers was saved: the position skips those pages on the next run,
     * so storing it first would lose them if the worker died before the save.
     */
    public function storeSavedCursor(): void
    {
        $cursor              = $this->unsavedCursor;
        $this->unsavedCursor = null;
        if ($cursor !== null) {
            $this->storeCursor($cursor['company'], $cursor['start']);
        }
    }

    private function storeCursor(string $companyUuid, ?int $start): void
    {
        if ($companyUuid === '' || class_exists(Connection::class) === false) {
            return;
        }

        try {
            // Only this company's own row. When it is gone (disconnected mid-import) the
            // cursor is dropped; another organization's connection is never written.
            $updated = (new Connection())->newQuery()->where('company_uuid', $companyUuid)->update([
                self::CURSOR_COLUMN => $start,
            ]);
            if ($updated === 0) {
                SafeLog::debug('QuickBooks customer import cursor not stored: the company has no connection.', ['company_uuid' => $companyUuid]);
            }
        } catch (\Throwable $exception) {
            // Unit tests keep the cursor on the ledger when the connections table is absent.
        }
    }

    /**
     * @param array<string, mixed> $connection
     * @param array<int, mixed>    $page
     * @param array<string, mixed> $batch
     */
    private function failPage(SyncLedger $ledger, array $connection, array $page, array &$batch): void
    {
        foreach ($page as $remote) {
            if (is_array($remote) === false) {
                continue;
            }
            if ($this->skippedRemote($remote) === true) {
                $batch['skipped'] = ($batch['skipped'] ?? 0) + 1;
                continue;
            }
            $batch['failed']    = ($batch['failed'] ?? 0) + 1;
            $ledger->attempts[] = [
                'company_uuid' => $connection['company_uuid'],
                'local_type'   => 'customer',
                'local_uuid'   => (string) ($remote['Id'] ?? ''),
                'outcome'      => 'failed',
                'error'        => 'quickbooks.messages.import-failed',
            ];
        }
    }

    /**
     * @param array<string, mixed> $remote
     */
    private function skippedRemote(array $remote): bool
    {
        if (array_key_exists('Active', $remote) === true && $remote['Active'] === false) {
            return true;
        }
        if (empty($remote['ParentRef']) === false) {
            return true;
        }

        return empty($remote['Job']) === false;
    }

    /**
     * One lookup for this page. The ledger hash index matches; local customers are not scanned per row.
     * The stored links of the page's QuickBooks customers and of the matching local customers
     * are loaded first, so a customer that is already linked is not linked or created again.
     *
     * @param array<string, mixed>             $connection
     * @param array<int, array<string, mixed>> $page
     */
    private function rememberCandidates(SyncLedger $ledger, array $connection, array $page): bool
    {
        [$emails, $names] = $this->candidateKeys($page);
        $class            = 'Fleetbase\\FleetOps\\Models\\Customer';
        $companyUuid      = (string) ($connection['company_uuid'] ?? '');
        try {
            $matching = [];
            if (($emails !== [] || $names !== []) && class_exists($class) === true) {
                $candidates = $this->candidateContacts($class, $companyUuid);
                $matching   = $this->matchingCandidates($candidates, $companyUuid, $emails, $names);
            }
            $this->loadLinks($ledger, $page, array_keys($matching));
        } catch (\Throwable $exception) {
            return false;
        }

        foreach ($matching as $uuid => $row) {
            $ledger->customers[$uuid] = $row;
            $ledger->rememberCustomer($uuid, $row);
        }

        return true;
    }

    /**
     * @param array<int, mixed>  $page
     * @param array<int, string> $localUuids
     */
    private function loadLinks(SyncLedger $ledger, array $page, array $localUuids): void
    {
        if ($this->linkLoader === null) {
            return;
        }
        $qboIds = [];
        foreach ($page as $remote) {
            if (is_array($remote) === true && (string) ($remote['Id'] ?? '') !== '') {
                $qboIds[] = (string) $remote['Id'];
            }
        }
        ($this->linkLoader)($ledger, $qboIds, array_map('strval', $localUuids));
        $this->rememberFleetbaseCustomers($ledger, $this->givenCustomers);
    }

    /**
     * The emails and names a page can match by. A phone number alone never matches.
     *
     * @param array<int, mixed> $page
     *
     * @return array{0: array<int, string>, 1: array<int, string>}
     */
    private function candidateKeys(array $page): array
    {
        $emails = [];
        $names  = [];
        foreach ($page as $remote) {
            if (is_array($remote) === false) {
                continue;
            }
            $email = strtolower(trim((string) ($remote['PrimaryEmailAddr']['Address'] ?? '')));
            $name  = CustomerMapper::displayName($this->displayName($remote));
            if ($email !== '') {
                $emails[] = $email;
            }
            if ($name !== '') {
                $names[] = $name;
            }
        }

        return [array_values(array_unique($emails)), array_values(array_unique($names))];
    }

    /**
     * The local customers that share an email or name with the page, keyed by uuid.
     *
     * @param iterable<mixed>    $candidates
     * @param array<int, string> $emails
     * @param array<int, string> $names
     *
     * @return array<string, array<string, mixed>>
     */
    private function matchingCandidates(
        iterable $candidates,
        string $companyUuid,
        array $emails,
        array $names,
    ): array {
        $emailSet = array_fill_keys($emails, true);
        $nameSet  = array_fill_keys($names, true);
        $matching = [];
        foreach ($candidates as $customer) {
            if (is_object($customer) === false) {
                continue;
            }
            $uuid = (string) ($customer->uuid ?? '');
            if ($uuid === '' || $this->candidateMatches($customer, $emailSet, $nameSet) === false) {
                continue;
            }
            $matching[$uuid] = [
                'uuid'         => $uuid,
                'company_uuid' => $companyUuid,
                'type'         => 'customer',
                'name'         => $customer->name,
                'email'        => $customer->email,
                'phone'        => $customer->phone,
            ];
        }

        return $matching;
    }

    /**
     * @param array<string, true> $emailSet
     * @param array<string, true> $nameSet
     */
    private function candidateMatches(object $customer, array $emailSet, array $nameSet): bool
    {
        $email = strtolower(trim((string) ($customer->email ?? '')));
        $name  = CustomerMapper::displayName((string) ($customer->name ?? ''));

        return ($email !== '' && isset($emailSet[$email]) === true)
            || ($name !== '' && isset($nameSet[$name]) === true);
    }

    /**
     * @param array<string, mixed>             $connection
     * @param array<int, array<string, mixed>> $fleetbaseCustomers
     * @param array<string, mixed>             $remote
     */
    private function importOne(SyncLedger $ledger, array $connection, array &$fleetbaseCustomers, array $remote): string
    {
        if ($this->skippedRemote($remote) === true) {
            return 'skipped';
        }

        $remoteId = (string) ($remote['Id'] ?? '');
        $company  = (string) ($connection['company_uuid'] ?? '');
        $realm    = (string) ($connection['realm_id'] ?? '');
        if ($remoteId !== '' && $ledger->linkForRemote($realm, 'Customer', $remoteId) !== null) {
            return 'linked';
        }
        if ($this->linkMatchedCustomer($ledger, $remote, $remoteId, $company, $realm) === true) {
            return 'linked';
        }

        return $this->createImportedCustomer($ledger, $connection, $fleetbaseCustomers, $remote);
    }

    /**
     * Link the Fleetbase customer with this email, or else the one with this name, unless it
     * already syncs with another QuickBooks customer. A taken email match does not hide a free
     * name match.
     *
     * @param array<string, mixed> $remote
     */
    private function linkMatchedCustomer(SyncLedger $ledger, array $remote, string $remoteId, string $company, string $realm): bool
    {
        $matches = $ledger->matchCustomers(
            strtolower((string) ($remote['PrimaryEmailAddr']['Address'] ?? '')),
            $this->displayName($remote)
        );
        foreach ($matches as $match) {
            $matchCompany = (string) ($match['company_uuid'] ?? $company);
            $link         = $ledger->link($matchCompany, $realm, 'customer', (string) $match['uuid']);
            $storedId     = is_array($link) === true ? (string) ($link['qbo_id'] ?? '') : '';
            if ($link === null || $storedId === '' || $storedId === $remoteId) {
                $this->linkMatch($ledger, $match, $link, $remoteId, $matchCompany, $realm);

                return true;
            }
        }

        return false;
    }

    /**
     * @param array<string, mixed>      $match
     * @param array<string, mixed>|null $link
     */
    private function linkMatch(
        SyncLedger $ledger,
        array $match,
        ?array $link,
        string $remoteId,
        string $matchCompany,
        string $realm,
    ): void {
        $storedId = is_array($link) === true ? (string) ($link['qbo_id'] ?? '') : '';
        if ($remoteId !== '') {
            // The Fleetbase customer keeps its own details, so this link stores no SyncToken: the
            // first sync treats the differences as QuickBooks changes. A stored token is kept.
            $kept = is_array($link) === true && $storedId === $remoteId ? (string) ($link['sync_token'] ?? '') : '';
            $ledger->putLink([
                'company_uuid' => $matchCompany,
                'realm_id'     => $realm,
                'local_type'   => 'customer',
                'local_uuid'   => $match['uuid'],
                'qbo_entity'   => 'Customer',
                'qbo_id'       => $remoteId,
                'sync_token'   => $kept,
            ]);
        }
    }

    /**
     * @param array<string, mixed>             $connection
     * @param array<int, array<string, mixed>> $fleetbaseCustomers
     * @param array<string, mixed>             $remote
     */
    private function createImportedCustomer(SyncLedger $ledger, array $connection, array &$fleetbaseCustomers, array $remote): string
    {
        try {
            SyncSuppressor::pause();
            $created = $this->creator !== null
                ? ($this->creator)($connection, $remote)
                : $this->customerFromRemote($connection, $remote);
            $ledger->customers[$created['uuid']] = $created;
            $ledger->rememberCustomer((string) $created['uuid'], $created);
            $fleetbaseCustomers[]                = $created;
            $ledger->putLink([
                'company_uuid' => $created['company_uuid'] ?? $connection['company_uuid'],
                'realm_id'     => $connection['realm_id'],
                'local_type'   => 'customer',
                'local_uuid'   => $created['uuid'],
                'qbo_entity'   => 'Customer',
                'qbo_id'       => (string) ($remote['Id'] ?? ''),
                'sync_token'   => (string) ($remote['SyncToken'] ?? '0'),
            ]);
            $this->created[(string) $created['uuid']] = true;
            SyncSuppressor::resume();
        } catch (\Throwable) {
            SyncSuppressor::resume();
            $ledger->attempts[] = [
                'company_uuid' => $connection['company_uuid'],
                'local_type'   => 'customer',
                'local_uuid'   => (string) ($remote['Id'] ?? ''),
                'outcome'      => 'failed',
                'error'        => 'quickbooks.messages.import-failed',
            ];

            return 'failed';
        }

        return 'created';
    }

    /**
     * @param array<string, mixed> $connection
     * @param array<string, mixed> $remote
     *
     * @return array<string, mixed>
     */
    private function customerFromRemote(array $connection, array $remote): array
    {
        $created = [
            'uuid'         => (string) Str::uuid(),
            'company_uuid' => $connection['company_uuid'],
            'type'         => 'customer',
            'name'         => $this->displayName($remote),
            'email'        => $remote['PrimaryEmailAddr']['Address'] ?? null,
            'phone'        => $remote['PrimaryPhone']['FreeFormNumber'] ?? null,
            'notes'        => $remote['Notes'] ?? null,
        ];
        $bill = $remote['BillAddr'] ?? null;
        if (is_array($bill) === true) {
            $address = (new CustomerMapper())->addressFromBillAddr($bill);
            if ($address !== null) {
                $created['address'] = $address;
            }
        }

        return $created;
    }

    /**
     * @param array<string, mixed> $remote
     */
    private function displayName(array $remote): string
    {
        if (empty($remote['DisplayName']) === false) {
            return (string) $remote['DisplayName'];
        }

        return trim((string) ($remote['GivenName'] ?? '') . ' ' . (string) ($remote['FamilyName'] ?? ''));
    }

    /**
     * Identity rows for this company's customers, loaded once per import.
     * The query filters on company and type so it can use that index. Email
     * case and name punctuation are compared in PHP, not in SQL.
     *
     * @param class-string $class
     *
     * @return array<int, object>
     */
    private function candidateContacts(string $class, string $companyUuid): array
    {
        if ($this->companyCandidates !== null && $this->companyCandidates['company'] === $companyUuid) {
            return $this->companyCandidates['rows'];
        }

        $model      = new $class();
        $connection = DB::connection($model->getConnectionName());
        $rows       = [];
        $after      = '';
        while (true) {
            $query = $connection->table($model->getTable())
                ->where('company_uuid', $companyUuid)
                ->where('type', 'customer')
                ->whereNull('deleted_at')
                ->orderBy('uuid')
                ->limit(500);
            if ($after !== '') {
                $query->where('uuid', '>', $after);
            }
            $page = $query->get(['uuid', 'name', 'email', 'phone']);
            if ($page->isEmpty() === true) {
                break;
            }
            $last = $after;
            foreach ($page as $row) {
                $rows[] = $row;
                $uuid   = (string) ($row->uuid ?? '');
                if ($uuid !== '') {
                    $after = $uuid;
                }
            }
            if ($after === $last || $page->count() < 500) {
                break;
            }
        }

        $this->companyCandidates = ['company' => $companyUuid, 'rows' => $rows];

        return $rows;
    }
}
