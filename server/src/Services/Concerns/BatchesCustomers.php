<?php

namespace Fleetbase\Quickbooks\Services\Concerns;

use Fleetbase\Quickbooks\Services\QuickBooksClient;
use Fleetbase\Quickbooks\Services\SyncLedger;
use Fleetbase\Quickbooks\Support\QuickBooksException;

/**
 * SyncEngine's customer blocks: look up every QuickBooks customer a block needs, by link, email
 * or name, in a few queries before the customers sync. Split from SyncsCustomers to keep each file readable.
 */
trait BatchesCustomers
{
    /**
     * @param array<string, mixed>             $connection
     * @param array<int, array<string, mixed>> $rows
     * @param array<string, mixed>             $settings
     * @param array<string, mixed>             $batch
     */
    private function syncCustomerBlock(SyncLedger $ledger, array &$connection, array $rows, array $settings, array &$batch, int $now, string $trigger, bool $record): bool
    {
        if ($this->entityEnabled($settings, 'customer') === false) {
            return $this->recordDisabledRows($ledger, $connection, $rows, $batch, $trigger, $record);
        }
        try {
            $this->prefetchCustomers($connection, $ledger, $rows);

            return $this->syncBufferedBlock($ledger, $connection, $rows, $settings, $batch, $now, $trigger, $record, 'customer');
        } catch (QuickBooksException $exception) {
            if ($exception->isUnauthorized() === true) {
                $this->tokenRejected($ledger, (string) $connection['company_uuid']);
                $batch['failed']++;

                return true;
            }
            if ($exception->isRateLimit() === true) {
                $this->failure($ledger, $connection, $rows[0], $exception, $settings, $now);
                if ($record === true) {
                    $this->recordRow($ledger, $connection, $rows[0], 'failed', $batch, $trigger, $this->exceptionError($exception));
                }

                return true;
            }

            throw $exception;
        } finally {
            $this->customerRemoteCache = null;
            if ($record === true) {
                $this->customerLookupFailed = [];
            }
        }
    }

    /**
     * @param array<string, mixed>             $connection
     * @param array<int, array<string, mixed>> $rows
     */
    private function prefetchCustomers(array $connection, SyncLedger $ledger, array $rows): void
    {
        $this->customerRemoteCache  = [];
        $this->customerLookupFailed = [];
        $nameOnly                   = [];
        $queries                    = $this->customerLookupQueries($connection, $ledger, $rows, $nameOnly);
        if ($queries === [] && $nameOnly === []) {
            return;
        }
        if (count($queries) === 1) {
            $this->prefetchOneCustomer($connection, $queries[0]);
        } elseif ($queries !== []) {
            $this->prefetchCustomerBatch($connection, $queries);
        }

        $nameQueries = $this->nameQueriesAfterEmail($connection, $ledger, $queries);
        foreach ($nameOnly as $query) {
            if (isset($this->customerLookupFailed[$query['uuid']]) === false) {
                $nameQueries[] = $query;
            }
        }
        $this->prefetchCustomerNames($connection, $nameQueries);
    }

    /**
     * The block's lookups: by link, else by email. A customer with neither is looked up by name.
     *
     * @param array<string, mixed>             $connection
     * @param array<int, array<string, mixed>> $rows
     * @param array<int, array{
     *     bId: string, query: string, uuid: string, email: string, name: string
     * }> $nameOnly filled in
     *
     * @return array<int, array{bId: string, query: string, uuid: string, by: string, value: string}>
     */
    private function customerLookupQueries(array $connection, SyncLedger $ledger, array $rows, array &$nameOnly): array
    {
        $companyUuid = (string) $connection['company_uuid'];
        $realm       = (string) $connection['realm_id'];
        $queries     = [];
        foreach ($rows as $row) {
            $uuid = (string) ($row['local_uuid'] ?? '');
            if ($uuid === '') {
                continue;
            }
            $customer = $ledger->customers[$uuid] ?? null;
            $link     = $ledger->link($companyUuid, $realm, 'customer', $uuid);
            if (is_array($link) === true && (string) ($link['qbo_id'] ?? '') !== '') {
                $remoteId  = (string) $link['qbo_id'];
                $queries[] = [
                    'bId'   => 'customer:' . $uuid,
                    'query' => "select * from Customer where Id = '" . QuickBooksClient::escapeQuery($remoteId) . "'",
                    'uuid'  => $uuid,
                    'by'    => 'id',
                    'value' => $remoteId,
                ];
                continue;
            }
            // A deleted customer without a link is never matched or created.
            if (is_array($customer) === false || empty($customer['deleted_at']) === false) {
                continue;
            }
            $email = trim((string) ($customer['email'] ?? ''));
            if ($email !== '') {
                $queries[] = [
                    'bId'   => 'customer:' . $uuid,
                    'query' => "select * from Customer where PrimaryEmailAddr = '"
                        . QuickBooksClient::escapeQuery($email) . "' maxresults 1",
                    'uuid'  => $uuid,
                    'by'    => 'email',
                    'value' => $email,
                ];
                continue;
            }
            $nameQuery = $this->customerNameQuery($uuid, $customer, '');
            if ($nameQuery !== null) {
                $nameOnly[] = $nameQuery;
            }
        }

        return $queries;
    }

    /**
     * The display-name lookup of a customer, or null when it has no name.
     *
     * @param array<string, mixed> $customer
     *
     * @return array{bId: string, query: string, uuid: string, email: string, name: string}|null
     */
    private function customerNameQuery(string $uuid, array $customer, string $email): ?array
    {
        $payload = $this->customers->toQuickBooks($this->customers->fromParty($customer));
        $name    = trim((string) ($payload['DisplayName'] ?? ''));
        if ($name === '') {
            return null;
        }

        return [
            'bId'   => 'customer-name:' . $uuid,
            'query' => "select * from Customer where DisplayName = '"
                . QuickBooksClient::escapeQuery($name) . "' maxresults 1",
            'uuid'  => $uuid,
            'email' => $email,
            'name'  => $name,
        ];
    }

    /**
     * Run the block's link and email lookups in one batch.
     *
     * @param array<string, mixed>                                                                   $connection
     * @param array<int, array{bId: string, query: string, uuid: string, by: string, value: string}> $queries
     */
    private function prefetchCustomerBatch(array $connection, array $queries): void
    {
        $items = [];
        foreach ($queries as $query) {
            $items[] = ['bId' => $query['bId'], 'query' => $query['query']];
        }
        try {
            $results = $this->client->batch($connection, $items);
        } catch (QuickBooksException $exception) {
            if ($exception->isUnauthorized() === true || $exception->isRateLimit() === true) {
                throw $exception;
            }
            foreach ($queries as $query) {
                $this->customerLookupFailed[$query['uuid']] = $exception;
            }
            $results = [];
        }
        $seen = [];
        foreach ($results as $bId => $result) {
            $uuid = substr((string) $bId, strlen('customer:'));
            if ($uuid === '' || $uuid === (string) $bId) {
                continue;
            }
            $seen[$uuid] = true;
            $this->storeCustomerLookup($uuid, $result);
        }
        foreach ($queries as $query) {
            $uuid = $query['uuid'];
            if (isset($seen[$uuid]) === false && isset($this->customerLookupFailed[$uuid]) === false) {
                $this->customerLookupFailed[$uuid] = $this->batchItemFailure(self::SYNC_FAILED_MESSAGE, 500);
            }
        }
    }

    /**
     * Keep one batched lookup: the customer it found (null for none), or why it failed. A halted
     * batch stops the block.
     */
    private function storeCustomerLookup(string $uuid, mixed $result): void
    {
        if (is_array($result) === true && empty($result['halt']) === false) {
            throw new QuickBooksException((int) ($result['status'] ?? 0), (string) ($result['error'] ?? ''));
        }
        if (is_array($result) === false || empty($result['ok']) === true) {
            $message                           = is_array($result) === true ? (string) ($result['error'] ?? '') : '';
            $status                            = is_array($result) === true ? (int) ($result['status'] ?? 0) : 0;
            $this->customerLookupFailed[$uuid] = $this->batchItemFailure($message, $status);

            return;
        }
        $remote                           = $result['rows'][0] ?? null;
        $this->customerRemoteCache[$uuid] = is_array($remote) === true ? $remote : null;
    }

    /**
     * A display-name lookup for each customer whose email found no QuickBooks customer, or found
     * one another Fleetbase customer already syncs with: a free name match is the same customer.
     *
     * @param array<string, mixed>                                                                   $connection
     * @param array<int, array{bId: string, query: string, uuid: string, by: string, value: string}> $queries
     *
     * @return array<int, array{bId: string, query: string, uuid: string, email: string, name: string}>
     */
    private function nameQueriesAfterEmail(array $connection, SyncLedger $ledger, array $queries): array
    {
        $taken       = $this->takenEmailMatches($connection, $ledger, $queries);
        $nameQueries = [];
        foreach ($queries as $query) {
            $uuid = $query['uuid'];
            if ($query['by'] !== 'email' || isset($this->customerLookupFailed[$uuid]) === true
                || array_key_exists($uuid, $this->customerRemoteCache) === false) {
                continue;
            }
            $remote = $this->customerRemoteCache[$uuid];
            if ($remote !== null && isset($taken[(string) ($remote['Id'] ?? '')]) === false) {
                continue;
            }
            $customer = $ledger->customers[$uuid] ?? null;
            if (is_array($customer) === false) {
                continue;
            }
            $nameQuery = $this->customerNameQuery($uuid, $customer, trim((string) ($customer['email'] ?? '')));
            if ($nameQuery !== null) {
                $nameQueries[] = $nameQuery;
            }
        }

        return $nameQueries;
    }

    /**
     * The QuickBooks ids, as keys, that the email lookups found and that some Fleetbase customer
     * already syncs with. One read covers the whole block. These customers have no link on this
     * realm, so no link of their own needs excluding.
     *
     * @param array<string, mixed>                                                                   $connection
     * @param array<int, array{bId: string, query: string, uuid: string, by: string, value: string}> $queries
     *
     * @return array<string, true>
     */
    private function takenEmailMatches(array $connection, SyncLedger $ledger, array $queries): array
    {
        $ids = [];
        foreach ($queries as $query) {
            $remote       = $query['by'] === 'email' ? ($this->customerRemoteCache[$query['uuid']] ?? null) : null;
            $remoteId     = is_array($remote) === true ? (string) ($remote['Id'] ?? '') : '';
            if ($remoteId !== '') {
                $ids[$remoteId] = $remoteId;
            }
        }
        if ($ids === []) {
            return [];
        }
        $taken = $ledger->remoteIdsLinked((string) $connection['realm_id'], 'Customer', array_values($ids), '');

        return array_fill_keys($taken, true);
    }

    /**
     * @param array<string, mixed>                                                       $connection
     * @param array{bId: string, query: string, uuid: string, by: string, value: string} $query
     */
    private function prefetchOneCustomer(array $connection, array $query): void
    {
        try {
            $remote = $query['by'] === 'id'
                ? $this->client->getCustomer($connection, $query['value'])
                : $this->client->findCustomerByEmail($connection, $query['value']);
        } catch (QuickBooksException $exception) {
            if ($exception->isUnauthorized() === true || $exception->isRateLimit() === true) {
                throw $exception;
            }
            $this->customerLookupFailed[$query['uuid']] = $exception;

            return;
        }
        $this->customerRemoteCache[$query['uuid']] = is_array($remote) === true ? $remote : null;
    }

    /**
     * A display-name match is the same customer whatever the emails say. A miss is cached too,
     * so the row is not queried again one name at a time.
     *
     * @param array<string, mixed>                                                                     $connection
     * @param array<int, array{bId: string, query: string, uuid: string, email: string, name: string}> $queries
     */
    private function prefetchCustomerNames(array $connection, array $queries): void
    {
        foreach (array_chunk($queries, QuickBooksClient::BATCH_LIMIT) as $chunk) {
            if (count($chunk) === 1) {
                $this->prefetchOneCustomerName($connection, $chunk[0]);
                continue;
            }
            try {
                $results = $this->client->batch($connection, array_map(
                    static fn (array $query): array => ['bId' => $query['bId'], 'query' => $query['query']],
                    $chunk
                ));
            } catch (QuickBooksException $exception) {
                if ($exception->isUnauthorized() === true || $exception->isRateLimit() === true) {
                    throw $exception;
                }
                foreach ($chunk as $query) {
                    $this->customerLookupFailed[$query['uuid']] = $exception;
                }
                continue;
            }
            foreach ($chunk as $query) {
                $this->storeCustomerLookup($query['uuid'], $results[$query['bId']] ?? null);
            }
        }
    }

    /**
     * @param array<string, mixed>                                                         $connection
     * @param array{bId: string, query: string, uuid: string, email: string, name: string} $query
     */
    private function prefetchOneCustomerName(array $connection, array $query): void
    {
        try {
            $remote = $this->client->findCustomerByDisplayName($connection, $query['name']);
        } catch (QuickBooksException $exception) {
            if ($exception->isUnauthorized() === true || $exception->isRateLimit() === true) {
                throw $exception;
            }
            $this->customerLookupFailed[$query['uuid']] = $exception;

            return;
        }
        $this->customerRemoteCache[$query['uuid']] = is_array($remote) === true ? $remote : null;
    }
}
