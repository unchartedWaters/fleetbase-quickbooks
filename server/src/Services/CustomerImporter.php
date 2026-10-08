<?php

namespace Fleetbase\Quickbooks\Services;

use Fleetbase\Quickbooks\Models\Connection;
use Fleetbase\Quickbooks\Support\CustomerMapper;
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
     * Email and phone rows for one import(), keyed by company. Reset when import() starts.
     *
     * @var array{company: string, rows: array<int, object>}|null
     */
    private ?array $companyCandidates = null;

    /**
     * @param callable|null $creator function(array $connection, array $remote): array
     */
    public function __construct(private QuickBooksClient $client, ?callable $creator = null)
    {
        $this->creator = $creator;
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

        $pageSize                = max(1, min(self::MAX_PAGE_SIZE, $pageSize));
        $this->companyCandidates = null;
        $ledger->ensureIndex();
        foreach ($fleetbaseCustomers as $customer) {
            if (is_array($customer) === true && (string) ($customer['uuid'] ?? '') !== '') {
                $ledger->rememberCustomer((string) $customer['uuid'], $customer);
            }
        }

        $start = $this->cursor($connection);
        while (true) {
            $page = $this->client->queryCustomers($connection, $start, $pageSize);
            if ($this->rememberCandidates($ledger, $connection, $page) === false) {
                $this->failPage($ledger, $connection, $page, $batch);
                break;
            }
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
            if ($failedAt !== null) {
                $next = $start + $failedAt;
                if ($next > 1) {
                    $this->writeCursor($ledger, $connection, $next);
                }
                break;
            }
            if (count($page) < $pageSize) {
                $this->writeCursor($ledger, $connection, 1);
                break;
            }
            $start += $pageSize;
            $this->writeCursor($ledger, $connection, $start);
            if ($deadline !== null && time() >= $deadline) {
                $batch['continue'] = true;
                break;
            }
        }

        $batch['finished_at'] = time();
        $ledger->batches[]    = $batch;

        return $batch;
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

        $this->storeCursor($companyUuid, $stored);
    }

    private function storeCursor(string $companyUuid, ?int $start): void
    {
        if ($companyUuid === '' || class_exists(Connection::class) === false) {
            return;
        }

        try {
            $updated = (new Connection())->newQuery()->where('company_uuid', $companyUuid)->update([
                self::CURSOR_COLUMN => $start,
            ]);
            if ($updated > 0) {
                return;
            }
            $rows = (new Connection())->newQuery()->limit(2)->get();
            if ($rows->count() !== 1) {
                return;
            }
            $only = $rows->first();
            if ($only instanceof Connection) {
                (new Connection())->newQuery()->whereKey($only->getKey())->update([
                    self::CURSOR_COLUMN => $start,
                ]);
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
                'error'        => 'Customer import failed.',
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
     *
     * @param array<string, mixed>             $connection
     * @param array<int, array<string, mixed>> $page
     */
    private function rememberCandidates(SyncLedger $ledger, array $connection, array $page): bool
    {
        $emails = [];
        $phones = [];
        $names  = [];
        foreach ($page as $remote) {
            if (is_array($remote) === false) {
                continue;
            }
            $email    = strtolower(trim((string) ($remote['PrimaryEmailAddr']['Address'] ?? '')));
            $phone    = $this->digits(trim((string) ($remote['PrimaryPhone']['FreeFormNumber'] ?? '')));
            $name     = $this->displayName($remote);
            if ($email !== '') {
                $emails[] = $email;
            }
            if ($phone !== '') {
                $phones[] = $phone;
            }
            if ($name !== '') {
                $names[] = $name;
            }
        }

        $emails = array_values(array_unique($emails));
        $phones = array_values(array_unique($phones));
        $names  = array_values(array_unique($names));
        if ($emails === [] && $phones === [] && $names === []) {
            return true;
        }

        $class = 'Fleetbase\\FleetOps\\Models\\Customer';
        if (class_exists($class) === false) {
            return true;
        }

        $companyUuid = (string) ($connection['company_uuid'] ?? '');
        try {
            $candidates = $this->candidateContacts($class, $companyUuid);
        } catch (\Throwable $exception) {
            return false;
        }

        $emailSet = array_fill_keys($emails, true);
        $phoneSet = array_fill_keys($phones, true);
        $nameSet  = array_fill_keys($names, true);
        foreach ($candidates as $customer) {
            $uuid = (string) ($customer->uuid ?? '');
            if ($uuid === '') {
                continue;
            }
            $email = strtolower(trim((string) ($customer->email ?? '')));
            $phone = $this->digits(trim((string) ($customer->phone ?? '')));
            $name  = (string) ($customer->name ?? '');
            $hit   = ($email !== '' && isset($emailSet[$email]) === true)
                || ($phone !== '' && isset($phoneSet[$phone]) === true)
                || ($name !== '' && isset($nameSet[$name]) === true);
            if ($hit === false) {
                continue;
            }
            $row = [
                'uuid'         => $uuid,
                'company_uuid' => $companyUuid,
                'type'         => 'customer',
                'name'         => $customer->name,
                'email'        => $customer->email,
                'phone'        => $customer->phone,
            ];
            $ledger->customers[$uuid] = $row;
            $ledger->rememberCustomer($uuid, $row);
        }

        return true;
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

        $match = $ledger->matchCustomer(
            strtolower((string) ($remote['PrimaryEmailAddr']['Address'] ?? '')),
            $this->digits((string) ($remote['PrimaryPhone']['FreeFormNumber'] ?? '')),
            $this->displayName($remote)
        );
        if ($match !== null) {
            $matchCompany = (string) ($match['company_uuid'] ?? $company);
            $link         = $ledger->link($matchCompany, $realm, 'customer', (string) $match['uuid']);
            $storedId     = is_array($link) === true ? (string) ($link['qbo_id'] ?? '') : '';
            if ($link === null || $storedId === '' || $storedId === $remoteId) {
                if ($remoteId !== '') {
                    $ledger->putLink([
                        'company_uuid' => $matchCompany,
                        'realm_id'     => $realm,
                        'local_type'   => 'customer',
                        'local_uuid'   => $match['uuid'],
                        'qbo_entity'   => 'Customer',
                        'qbo_id'       => $remoteId,
                        'sync_token'   => (string) ($remote['SyncToken'] ?? '0'),
                    ]);
                }

                return 'linked';
            }
        }

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
            SyncSuppressor::resume();
        } catch (\Throwable) {
            SyncSuppressor::resume();
            $ledger->attempts[] = [
                'company_uuid' => $connection['company_uuid'],
                'local_type'   => 'customer',
                'local_uuid'   => (string) ($remote['Id'] ?? ''),
                'outcome'      => 'failed',
                'error'        => 'Customer import failed.',
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

    private function digits(string $value): string
    {
        return preg_replace('/\D+/', '', $value) ?? '';
    }

    /**
     * Identity rows for this company's customers, loaded once per import.
     * The query filters on company and type so it can use that index. Email
     * case and phone punctuation are compared in PHP, not in SQL.
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
