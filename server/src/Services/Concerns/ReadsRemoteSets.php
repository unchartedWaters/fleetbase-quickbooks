<?php

namespace Fleetbase\Quickbooks\Services\Concerns;

use Fleetbase\Quickbooks\Services\QuickBooksClient;
use Fleetbase\Quickbooks\Support\QuickBooksException;

/**
 * SyncEngine's read of several QuickBooks records by id. It shares the engine's client and error
 * texts, so it lives here only to keep SyncEngine.php readable.
 */
trait ReadsRemoteSets
{
    /**
     * One id uses a direct read. Null is a real miss. A thrown error stays thrown.
     * Several ids are queried in chunks of BATCH_LIMIT. Each query is paged with
     * startposition and maxresults so a row past QuickBooks' default page is not
     * treated as missing. A successful query that omits an id is a miss.
     * A failed batch throws. Those ids are not returned as an empty success.
     *
     * @param array<string, mixed> $connection
     * @param array<int, string>   $ids
     *
     * @return array<string, array<string, mixed>>
     */
    private function readRemoteSet(array $connection, string $entity, array $ids): array
    {
        $ids = array_values(array_unique(array_filter($ids, static fn (string $id): bool => $id !== '')));
        if ($ids === []) {
            return [];
        }
        if (count($ids) === 1) {
            $id     = $ids[0];
            $remote = match ($entity) {
                'Customer' => $this->client->getCustomer($connection, $id),
                'Invoice'  => $this->client->getInvoice($connection, $id),
                'Payment'  => $this->client->getPayment($connection, $id),
                'Account'  => $this->client->getAccount($connection, $id),
                default    => null,
            };

            return is_array($remote) === true ? [$id => $remote] : [];
        }

        $mapped = [];
        foreach (array_chunk($ids, QuickBooksClient::BATCH_LIMIT) as $chunkIndex => $chunk) {
            $found = count($chunk) === 1
                ? $this->readRemoteSet($connection, $entity, $chunk)
                : $this->readRemoteChunk($connection, $entity, $chunk, $chunkIndex);
            foreach ($found as $id => $remote) {
                $mapped[$id] = $remote;
            }
        }

        return $mapped;
    }

    /**
     * Every page of one "Id IN (...)" query.
     *
     * @param array<string, mixed> $connection
     * @param array<int, string>   $chunk
     *
     * @return array<string, array<string, mixed>>
     */
    private function readRemoteChunk(array $connection, string $entity, array $chunk, int $chunkIndex): array
    {
        $mapped = [];
        $start  = 1;
        for ($pages = 0; $pages < 100; $pages++) {
            $query = 'select * from ' . $entity . ' where Id IN (' . QuickBooksClient::quotedList($chunk) . ')'
                . ' startposition ' . $start . ' maxresults ' . self::QUERY_PAGE_SIZE;
            $rows  = $this->readRemotePage($connection, 'read-' . $chunkIndex . '-' . $start, $query);
            foreach ($rows as $remote) {
                $id = (string) ($remote['Id'] ?? '');
                if ($id !== '') {
                    $mapped[$id] = $remote;
                }
            }
            if (count($rows) < self::QUERY_PAGE_SIZE) {
                break;
            }
            $start += self::QUERY_PAGE_SIZE;
        }

        return $mapped;
    }

    /**
     * One query page through the batch endpoint. A failed or halted page throws.
     *
     * @param array<string, mixed> $connection
     *
     * @return array<int, array<string, mixed>>
     */
    private function readRemotePage(array $connection, string $bId, string $query): array
    {
        try {
            $results = $this->client->batch($connection, [['bId' => $bId, 'query' => $query]]);
        } catch (QuickBooksException $exception) {
            if ($exception->isUnauthorized() === true || $exception->isRateLimit() === true) {
                throw $exception;
            }

            $wrapped = new QuickBooksException(
                $exception->status,
                $this->exceptionError($exception),
                $exception->retryAfter,
                $exception->faultCode
            );

            throw $wrapped;
        }

        $result = $results[$bId] ?? null;
        if (is_array($result) === true && empty($result['halt']) === false) {
            throw new QuickBooksException((int) ($result['status'] ?? 0), (string) ($result['error'] ?? ''));
        }
        if (is_array($result) === false || empty($result['ok']) === true) {
            $message = is_array($result) === true ? (string) ($result['error'] ?? '') : '';
            $status  = is_array($result) === true ? (int) ($result['status'] ?? 0) : 0;
            $error   = $this->batchItemAttemptError($message, $status);
            throw new QuickBooksException($status, $error !== '' ? $error : self::SYNC_FAILED_MESSAGE);
        }

        $rows = is_array($result['rows'] ?? null) === true ? $result['rows'] : [];

        return array_values(array_filter($rows, 'is_array'));
    }
}
