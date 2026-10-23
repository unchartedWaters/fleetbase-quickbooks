<?php

namespace Fleetbase\Quickbooks\Services;

use Fleetbase\Quickbooks\Support\CustomerMapper;

/**
 * In-memory snapshot the sync engine mutates. Production loads and saves it through Eloquent.
 *
 * @phpstan-type Connection array<string, mixed>
 * @phpstan-type Link array<string, mixed>
 * @phpstan-type Pending array<string, mixed>
 * @phpstan-type Customer array<string, mixed>
 * @phpstan-type Invoice array<string, mixed>
 * @phpstan-type Wallet array<string, mixed>
 */
class SyncLedger
{
    /** @var array<string, Connection> */
    public array $connections = [];

    /** @var array<int, Link> */
    public array $links = [];

    /** @var array<int, Pending> */
    public array $pending = [];

    /** @var array<string, Customer> */
    public array $customers = [];

    /** @var array<string, Invoice> */
    public array $invoices = [];

    /** @var array<string, Wallet> */
    public array $wallets = [];

    /**
     * Storefront products by uuid: those of the pending product rows, deleted or draft included,
     * and every live product sharing a name with a product, a QuickBooks product link or an
     * invoice line this run handles.
     *
     * @var array<string, array<string, mixed>>
     */
    public array $products = [];

    /**
     * Storefront products could not be read, so no product counts as gone.
     */
    public bool $productsUnavailable = false;

    /**
     * Storefront is installed without its tables: there are no products, and none counts as gone.
     */
    public bool $productsMissing = false;

    /** @var array<int, array<string, mixed>> */
    public array $batches = [];

    /** @var array<int, array<string, mixed>> */
    public array $attempts = [];

    /** @var array<int, array<string, mixed>> */
    public array $remoteInvoices = [];

    /**
     * Reads which QuickBooks ids have a link in a realm other than one of the local record:
     * (realm, entity, ids, local uuid) => linked ids.
     *
     * @var (\Closure(string, string, array<int, string>, string): array<int, string>)|null
     */
    public ?\Closure $remoteLinkReader = null;

    /**
     * Reads the customer link of a QuickBooks customer this run did not load:
     * (realm, QuickBooks id) => link, or null when it has none or its customer is gone.
     *
     * @var (\Closure(string, string): (array<string, mixed>|null))|null
     */
    public ?\Closure $customerLinkReader = null;

    private bool $indexed = false;

    /** @var array<string, int> */
    private array $linksByIdentity = [];

    /** @var array<string, int> */
    private array $linksByRemote = [];

    /** @var array<int, string> */
    private array $remoteKeyByIndex = [];

    /** @var array<string, string> */
    private array $customersByEmail = [];

    /** @var array<string, string> */
    private array $customersByName = [];

    /** @var array<string, array{email: string, name: string}> */
    private array $customerKeys = [];

    /** @var array<string, array<string, mixed>> */
    private array $customerRows = [];

    /** @var array<string, string> */
    private array $invoicesByNumber = [];

    /** @var array<string, string> */
    private array $invoiceNumbers = [];

    /**
     * @return Connection|null
     */
    public function connection(string $companyUuid): ?array
    {
        return $this->connections[$companyUuid] ?? null;
    }

    /**
     * @return Link|null
     */
    public function link(string $companyUuid, string $realmId, string $localType, string $localUuid): ?array
    {
        $this->ensureIndex();
        $index = $this->linksByIdentity[$this->identity($companyUuid, $localType, $localUuid)] ?? null;
        if ($index === null) {
            return null;
        }

        $link = $this->links[$index] ?? null;

        return is_array($link) === true && (string) ($link['realm_id'] ?? '') === $realmId ? $link : null;
    }

    public function hasRemoteLink(string $realmId, string $entity, string $qboId): bool
    {
        $this->ensureIndex();

        return isset($this->linksByRemote[$realmId . '|' . $entity . '|' . $qboId]);
    }

    /**
     * The QuickBooks ids among $ids that some local record in this realm is linked to, read
     * beyond the rows loaded here when the directory set a reader.
     *
     * @param array<int, string> $ids
     *
     * @return array<int, string>
     */
    public function remoteIdsLinked(string $realmId, string $entity, array $ids, string $localUuid): array
    {
        $linked = [];
        foreach ($ids as $id) {
            $link = $this->linkForRemote($realmId, $entity, $id);
            if ($link !== null && (string) ($link['local_uuid'] ?? '') !== $localUuid) {
                $linked[] = $id;
            }
        }
        if ($this->remoteLinkReader !== null && $ids !== []) {
            $read   = ($this->remoteLinkReader)($realmId, $entity, array_values($ids), $localUuid);
            $linked = array_merge($linked, $read);
        }

        return array_values(array_unique(array_map('strval', $linked)));
    }

    /**
     * @return Link|null
     */
    public function linkForRemote(string $realmId, string $entity, string $qboId): ?array
    {
        $this->ensureIndex();
        $index = $this->linksByRemote[$realmId . '|' . $entity . '|' . $qboId] ?? null;
        if ($index === null) {
            return null;
        }

        $link = $this->links[$index] ?? null;

        return is_array($link) === true ? $link : null;
    }

    /**
     * One pass over the rows this batch loaded. Later lookups use the maps.
     */
    public function rebuildIndex(): void
    {
        $this->linksByIdentity  = [];
        $this->linksByRemote    = [];
        $this->remoteKeyByIndex = [];
        $this->customersByEmail = [];
        $this->customersByName  = [];
        $this->customerKeys     = [];
        $this->customerRows     = [];
        $this->invoicesByNumber = [];
        $this->invoiceNumbers   = [];
        $this->indexed          = true;
        foreach ($this->links as $index => $link) {
            $this->rememberLink((int) $index, $link);
        }
        foreach ($this->customers as $uuid => $customer) {
            $this->rememberCustomer((string) $uuid, $customer);
        }
        foreach ($this->invoices as $uuid => $invoice) {
            $this->rememberInvoice((string) $uuid, $invoice);
        }
        $this->indexed = true;
    }

    public function ensureIndex(): void
    {
        if ($this->indexed === false) {
            $this->rebuildIndex();
        }
    }

    /**
     * @param array<string, mixed> $customer
     */
    public function rememberCustomer(string $uuid, array $customer): void
    {
        $this->ensureIndex();
        $previous = $this->customerKeys[$uuid] ?? null;
        if ($previous !== null) {
            $this->forgetCustomerKey($this->customersByEmail, $previous['email'], $uuid);
            $this->forgetCustomerKey($this->customersByName, $previous['name'], $uuid);
        }

        $email = strtolower(trim((string) ($customer['email'] ?? '')));
        $name  = CustomerMapper::displayName((string) ($customer['name'] ?? ''));
        if ($email !== '' && isset($this->customersByEmail[$email]) === false) {
            $this->customersByEmail[$email] = $uuid;
        }
        if ($name !== '' && isset($this->customersByName[$name]) === false) {
            $this->customersByName[$name] = $uuid;
        }
        $this->customerKeys[$uuid] = ['email' => $email, 'name' => $name];
        $this->customerRows[$uuid] = $customer;
    }

    public function touchCustomer(string $uuid): void
    {
        $customer = $this->customers[$uuid] ?? null;
        if (is_array($customer) === true) {
            $this->rememberCustomer($uuid, $customer);
        }
    }

    /**
     * @param array<string, mixed> $invoice
     */
    public function rememberInvoice(string $uuid, array $invoice): void
    {
        $this->ensureIndex();
        $previous = $this->invoiceNumbers[$uuid] ?? '';
        if ($previous !== '' && ($this->invoicesByNumber[$previous] ?? null) === $uuid) {
            unset($this->invoicesByNumber[$previous]);
        }
        $number = trim((string) ($invoice['number'] ?? ''));
        if ($number !== '') {
            $this->invoicesByNumber[$number] = $uuid;
        }
        $this->invoiceNumbers[$uuid] = $number;
    }

    /**
     * The customer with this email, then the one with this display name, each at most once.
     * The caller takes the first one free to link. Empty keys are skipped. A phone number
     * alone never matches. Names are compared the way QuickBooks stores them (a colon becomes
     * a dash).
     *
     * @return array<int, array<string, mixed>>
     */
    public function matchCustomers(string $email, string $name): array
    {
        $this->ensureIndex();
        $email = strtolower(trim($email));
        $name  = CustomerMapper::displayName($name);
        $uuids = [];
        if ($email !== '' && isset($this->customersByEmail[$email]) === true) {
            $uuids[] = $this->customersByEmail[$email];
        }
        if ($name !== '' && isset($this->customersByName[$name]) === true) {
            $uuids[] = $this->customersByName[$name];
        }
        $matches = [];
        foreach (array_unique($uuids) as $uuid) {
            $row = $this->customerRow($uuid);
            if ($row !== null) {
                $matches[] = $row;
            }
        }

        return $matches;
    }

    /**
     * @param array<string, mixed> $attributes
     */
    public function putLink(array $attributes): void
    {
        $this->ensureIndex();
        $key   = $this->identity(
            (string) ($attributes['company_uuid'] ?? ''),
            (string) ($attributes['local_type'] ?? ''),
            (string) ($attributes['local_uuid'] ?? '')
        );
        $index = $this->linksByIdentity[$key] ?? null;
        if ($index !== null && isset($this->links[$index]) === true) {
            $this->links[$index] = array_merge($this->links[$index], $attributes);
            $this->rememberLink($index, $this->links[$index]);

            return;
        }

        $this->links[] = $attributes;
        $index         = array_key_last($this->links);
        $this->rememberLink((int) $index, $attributes);
    }

    /**
     * @return array<string, mixed>|null
     */
    private function customerRow(string $uuid): ?array
    {
        $row = $this->customerRows[$uuid] ?? $this->customers[$uuid] ?? null;

        return is_array($row) === true ? $row : null;
    }

    /**
     * @param array<string, string> $map
     */
    private function forgetCustomerKey(array &$map, string $key, string $uuid): void
    {
        if ($key !== '' && ($map[$key] ?? null) === $uuid) {
            unset($map[$key]);
        }
    }

    private function identity(string $companyUuid, string $localType, string $localUuid): string
    {
        return $companyUuid . '|' . $localType . '|' . $localUuid;
    }

    /**
     * @param array<string, mixed> $link
     */
    private function rememberLink(int $index, array $link): void
    {
        $previous = $this->remoteKeyByIndex[$index] ?? null;
        if ($previous !== null) {
            unset($this->linksByRemote[$previous]);
        }

        $this->linksByIdentity[$this->identity(
            (string) ($link['company_uuid'] ?? ''),
            (string) ($link['local_type'] ?? ''),
            (string) ($link['local_uuid'] ?? '')
        )] = $index;

        $entity = (string) ($link['qbo_entity'] ?? '');
        $qboId  = (string) ($link['qbo_id'] ?? '');
        $realm  = (string) ($link['realm_id'] ?? '');
        if ($entity === '' || $qboId === '' || $realm === '') {
            unset($this->remoteKeyByIndex[$index]);

            return;
        }

        $remote                              = $realm . '|' . $entity . '|' . $qboId;
        $this->linksByRemote[$remote]        = $index;
        $this->remoteKeyByIndex[$index]      = $remote;
    }

    public function upsertPending(string $companyUuid, string $localType, string $localUuid, string $reason): void
    {
        foreach ($this->pending as $row) {
            if ($row['company_uuid'] === $companyUuid && $row['local_type'] === $localType && $row['local_uuid'] === $localUuid && ($row['status'] ?? 'pending') === 'pending') {
                return;
            }
        }

        $this->pending[] = [
            'company_uuid'   => $companyUuid,
            'local_type'     => $localType,
            'local_uuid'     => $localUuid,
            'reason'         => $reason,
            'status'         => 'pending',
            'attempts'       => 0,
            'next_attempt_at'=> null,
        ];
    }

    /**
     * Due rows in the order FleetbaseDirectory loads them: edits before the reconcile backlog,
     * then rows never tried before rows waiting out a backoff, then by due time.
     *
     * @return array<int, Pending>
     */
    public function duePending(string $companyUuid, int $limit, int $now): array
    {
        $rows = [];
        foreach ($this->pending as $row) {
            if ($row['company_uuid'] !== $companyUuid || ($row['status'] ?? '') !== 'pending') {
                continue;
            }
            $next = $row['next_attempt_at'] ?? null;
            if ($next !== null && (int) $next > $now) {
                continue;
            }
            $rows[] = $row;
        }
        usort($rows, static fn (array $left, array $right): int => self::dueOrder($left) <=> self::dueOrder($right));

        return array_slice($rows, 0, max(1, $limit));
    }

    /**
     * 0 for an edit made in Fleetbase or QuickBooks, 1 for a reconcile or catalog row.
     *
     * @param Pending $row
     */
    public static function pendingPriority(array $row): int
    {
        if (isset($row['priority']) === true && is_numeric($row['priority']) === true) {
            return (int) $row['priority'];
        }

        return ($row['reason'] ?? null) === 'reconcile' ? 1 : 0;
    }

    /**
     * @param Pending $row
     *
     * @return array{0: int, 1: int, 2: int}
     */
    private static function dueOrder(array $row): array
    {
        $next = $row['next_attempt_at'] ?? null;

        return [self::pendingPriority($row), $next === null ? 0 : 1, $next === null ? 0 : (int) $next];
    }

    /**
     * Record a batch that did not run, with the reason Activity shows.
     *
     * @return array<string, mixed>
     */
    public function skip(string $companyUuid, string $trigger, string $direction, string $message, int $now): array
    {
        $batch = [
            'company_uuid' => $companyUuid,
            'trigger'      => $trigger,
            'direction'    => $direction,
            'status'       => 'skipped',
            'created'      => 0,
            'updated'      => 0,
            'aligned'      => 0,
            'voided'       => 0,
            'unmatched'    => 0,
            'failed'       => 0,
            'started_at'   => $now,
            'finished_at'  => $now,
        ];
        $this->batches[]  = $batch;
        $this->attempts[] = [
            'company_uuid' => $companyUuid,
            'local_type'   => 'connection',
            'local_uuid'   => $companyUuid,
            'outcome'      => 'skipped',
            'error'        => $message,
        ];

        return $batch;
    }

    /**
     * A run that stopped before it synced anything, recorded as failed with its reason.
     *
     * @return array<string, mixed>
     */
    public function fail(string $companyUuid, string $trigger, string $direction, string $message, int $now): array
    {
        $batch            = array_merge($this->skip($companyUuid, $trigger, $direction, $message, $now), [
            'status' => 'failed',
            'failed' => 1,
        ]);
        $attempt          = array_merge((array) array_pop($this->attempts), ['outcome' => 'failed']);
        $this->attempts[] = $attempt;
        array_pop($this->batches);
        $this->batches[] = $batch;

        return $batch;
    }

    /**
     * @param array<string, mixed> $changes
     */
    public function updatePending(string $companyUuid, string $localType, string $localUuid, array $changes): void
    {
        foreach ($this->pending as $index => $row) {
            if ($row['company_uuid'] === $companyUuid && $row['local_type'] === $localType && $row['local_uuid'] === $localUuid && ($row['status'] ?? '') === 'pending') {
                $this->pending[$index] = array_merge($row, $changes);

                return;
            }
        }
    }
}
