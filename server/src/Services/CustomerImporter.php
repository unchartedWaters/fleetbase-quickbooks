<?php

namespace Fleetbase\Quickbooks\Services;

use Fleetbase\Quickbooks\Models\Connection;
use Fleetbase\Quickbooks\Support\SyncSuppressor;
use Illuminate\Support\Str;

class CustomerImporter
{
    public const CURSOR_COLUMN = 'customer_import_start';

    public const MAX_PAGE_SIZE = 100;

    /** @var callable|null */
    private $creator;

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

        if (!$enabled) {
            $ledger->batches[] = $batch;

            return $batch;
        }

        $pageSize = max(1, min(self::MAX_PAGE_SIZE, $pageSize));
        $ledger->ensureIndex();
        foreach ($fleetbaseCustomers as $customer) {
            if (is_array($customer) && (string) ($customer['uuid'] ?? '') !== '') {
                $ledger->rememberCustomer((string) $customer['uuid'], $customer);
            }
        }

        $start = $this->cursor($connection);
        do {
            $page = $this->client->queryCustomers($connection, $start, $pageSize);
            $this->rememberCandidates($ledger, $connection, $page);
            foreach ($page as $remote) {
                if (!is_array($remote)) {
                    continue;
                }
                $outcome         = $this->importOne($ledger, $connection, $fleetbaseCustomers, $remote);
                $batch[$outcome] = ($batch[$outcome] ?? 0) + 1;
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
        } while (true);

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
            if ($companyUuid !== '' && isset($ledger->connections[$companyUuid])) {
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
        if ($companyUuid === '' || !class_exists(Connection::class)) {
            return;
        }

        try {
            (new Connection())->newQuery()->where('company_uuid', $companyUuid)->update([
                self::CURSOR_COLUMN => $start,
            ]);
        } catch (\Throwable $exception) {
            // Unit tests keep the cursor on the ledger when the connections table is absent.
        }
    }

    /**
     * One lookup for this page. The ledger hash index matches; local customers are not scanned per row.
     *
     * @param array<string, mixed>              $connection
     * @param array<int, array<string, mixed>>  $page
     */
    private function rememberCandidates(SyncLedger $ledger, array $connection, array $page): void
    {
        $emails = [];
        $phones = [];
        $names  = [];
        foreach ($page as $remote) {
            if (!is_array($remote)) {
                continue;
            }
            $email    = strtolower(trim((string) ($remote['PrimaryEmailAddr']['Address'] ?? '')));
            $rawPhone = trim((string) ($remote['PrimaryPhone']['FreeFormNumber'] ?? ''));
            $phone    = $this->digits($rawPhone);
            $name     = $this->displayName($remote);
            if ($email !== '') {
                $emails[] = $email;
            }
            if ($rawPhone !== '') {
                $phones[] = $rawPhone;
            }
            if ($phone !== '' && $phone !== $rawPhone) {
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
            return;
        }

        $class = 'Fleetbase\\FleetOps\\Models\\Customer';
        if (!class_exists($class)) {
            return;
        }

        $companyUuid = (string) ($connection['company_uuid'] ?? '');
        try {
            $query = $class::query()->where('company_uuid', $companyUuid)->where(function ($scope) use ($emails, $phones, $names): void {
                $started = false;
                if ($emails !== []) {
                    $scope->whereIn('email', $emails);
                    $started = true;
                }
                if ($phones !== []) {
                    $method = $started ? 'orWhereIn' : 'whereIn';
                    $scope->{$method}('phone', $phones);
                    $started = true;
                }
                if ($names !== []) {
                    $method = $started ? 'orWhereIn' : 'whereIn';
                    $scope->{$method}('name', $names);
                }
            });
            $candidates = $query->get();
        } catch (\Throwable $exception) {
            return;
        }

        foreach ($candidates as $customer) {
            $uuid = (string) ($customer->uuid ?? '');
            if ($uuid === '') {
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
    }

    /**
     * @param array<string, mixed>             $connection
     * @param array<int, array<string, mixed>> $fleetbaseCustomers
     * @param array<string, mixed>             $remote
     */
    private function importOne(SyncLedger $ledger, array $connection, array &$fleetbaseCustomers, array $remote): string
    {
        if (array_key_exists('Active', $remote) && $remote['Active'] === false) {
            return 'skipped';
        }
        if (!empty($remote['ParentRef'])) {
            return 'skipped';
        }
        if (!empty($remote['Job'])) {
            return 'skipped';
        }

        $match = $ledger->matchCustomer(
            strtolower((string) ($remote['PrimaryEmailAddr']['Address'] ?? '')),
            $this->digits((string) ($remote['PrimaryPhone']['FreeFormNumber'] ?? '')),
            $this->displayName($remote)
        );
        if ($match !== null) {
            if ($ledger->link((string) $connection['company_uuid'], (string) $connection['realm_id'], 'customer', (string) $match['uuid']) === null) {
                $ledger->putLink([
                    'company_uuid' => $connection['company_uuid'],
                    'realm_id'     => $connection['realm_id'],
                    'local_type'   => 'customer',
                    'local_uuid'   => $match['uuid'],
                    'qbo_entity'   => 'Customer',
                    'qbo_id'       => (string) ($remote['Id'] ?? ''),
                    'sync_token'   => (string) ($remote['SyncToken'] ?? '0'),
                ]);
            }

            return 'linked';
        }

        try {
            SyncSuppressor::pause();
            $created = $this->creator !== null
                ? ($this->creator)($connection, $remote)
                : [
                    'uuid'         => (string) Str::uuid(),
                    'company_uuid' => $connection['company_uuid'],
                    'type'         => 'customer',
                    'name'         => $this->displayName($remote),
                    'email'        => $remote['PrimaryEmailAddr']['Address'] ?? null,
                    'phone'        => $remote['PrimaryPhone']['FreeFormNumber'] ?? null,
                    'notes'        => $remote['Notes'] ?? null,
                    'meta'         => ['quickbooks_bill_addr' => $remote['BillAddr'] ?? null],
                ];
            $ledger->customers[$created['uuid']] = $created;
            $ledger->rememberCustomer((string) $created['uuid'], $created);
            $fleetbaseCustomers[]                = $created;
            $ledger->putLink([
                'company_uuid' => $connection['company_uuid'],
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
     * @param array<string, mixed> $remote
     */
    private function displayName(array $remote): string
    {
        if (!empty($remote['DisplayName'])) {
            return (string) $remote['DisplayName'];
        }

        return trim((string) ($remote['GivenName'] ?? '') . ' ' . (string) ($remote['FamilyName'] ?? ''));
    }

    private function digits(string $value): string
    {
        return preg_replace('/\D+/', '', $value) ?? '';
    }
}
