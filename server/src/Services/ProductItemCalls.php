<?php

namespace Fleetbase\Quickbooks\Services;

use Fleetbase\Quickbooks\Support\QuickBooksException;

/**
 * The QuickBooks reads and writes of ProductItems: products by Id or Name, and creates or
 * sparse updates, one call for one write, else the Batch API. A 401 or a rate limit is thrown.
 *
 * @phpstan-import-type Result from ProductItems
 * @phpstan-import-type Write from ProductItems
 * @phpstan-import-type Operation from ProductItems
 * @phpstan-import-type Found from ProductItems
 *
 * @phpstan-type Answer array{
 *     ok: bool, body: array<string, mixed>, rows: array<int, array<string, mixed>>, error: string|null,
 *     status: int, halt: bool, code?: string
 * }
 */
class ProductItemCalls
{
    private const DUPLICATE_NAME_FAULT = '6240';

    private const STALE_TOKEN_FAULT = '5010';

    private const QUERY_LIMIT = 1000;

    public function __construct(private QuickBooksClient $client)
    {
    }

    /**
     * Read QuickBooks products by Id or Name, active and inactive, in one batch of queries.
     *
     * @param array<string, mixed> $connection
     * @param array<int, string>   $values
     *
     * @return Found rows by Id
     */
    public function read(array $connection, string $field, array $values): array
    {
        $values = array_values(array_unique(array_filter(
            array_map('trim', $values),
            static fn (string $value): bool => $value !== ''
        )));
        if ($values === []) {
            return ['rows' => [], 'failed' => null];
        }
        $queries = [];
        foreach (array_chunk($values, QuickBooksClient::BATCH_LIMIT) as $index => $chunk) {
            $queries[] = [
                'bId'   => 'items-' . $field . '-' . $index,
                'query' => 'select * from Item where Active IN (true, false) and ' . $field . ' IN ('
                    . QuickBooksClient::quotedList($chunk) . ') maxresults ' . self::QUERY_LIMIT,
            ];
        }
        try {
            $answers = $this->client->batch($connection, $queries);
        } catch (QuickBooksException $exception) {
            if ($exception->isUnauthorized() === true || $exception->isRateLimit() === true) {
                throw $exception;
            }

            return ['rows' => [], 'failed' => $exception->getMessage()];
        }
        $rows = [];
        foreach ($queries as $query) {
            $answer = $answers[$query['bId']] ?? null;
            if ($answer !== null && $answer['halt'] === true) {
                throw new QuickBooksException($answer['status'], (string) $answer['error']);
            }
            if ($answer === null || $answer['ok'] === false) {
                return ['rows' => [], 'failed' => (string) ($answer['error'] ?? 'quickbooks.messages.query-failed')];
            }
            foreach ($answer['rows'] as $row) {
                $itemId = trim((string) ($row['Id'] ?? ''));
                if ($itemId !== '') {
                    $rows[$itemId] = $row;
                }
            }
        }

        return ['rows' => $rows, 'failed' => null];
    }

    /**
     * Send the writes, one call for one write, else the Batch API.
     *
     * @param array<string, mixed> $connection
     * @param array<string, Write> $writes     by key
     *
     * @return array<string, Result> by key
     */
    public function write(array $connection, array $writes): array
    {
        if ($writes === []) {
            return [];
        }
        $operations = array_values(array_map(static fn (array $write): array => $write['write'], $writes));
        $answers    = count($operations) === 1
            ? [$operations[0]['bId'] => $this->writeOne($connection, $operations[0])]
            : $this->client->batch($connection, $operations);

        $results = [];
        foreach ($writes as $key => $write) {
            $results[$key] = $this->written($write, $answers[$write['write']['bId']] ?? null);
        }

        return $results;
    }

    /**
     * @param Write       $write
     * @param Answer|null $answer
     *
     * @return Result
     */
    private function written(array $write, ?array $answer): array
    {
        if ($answer !== null && $answer['halt'] === true) {
            throw new QuickBooksException($answer['status'], (string) $answer['error']);
        }
        $body = $answer['body'] ?? [];
        if ($answer === null || $answer['ok'] === false || trim((string) ($body['Id'] ?? '')) === '') {
            $status = $answer['status'] ?? 400;
            $error  = ($answer['code'] ?? '') === self::DUPLICATE_NAME_FAULT
                ? ProductItems::nameTaken($write['name'])
                : (string) ($answer['error'] ?? 'quickbooks.messages.batch-item-missing');

            return ProductItems::failed($error, (new QuickBooksException($status, $error))->isTransient());
        }
        $outcome = $write['write']['operation'] === 'create' ? 'created' : 'updated';
        $active  = ($body['Active'] ?? true) !== false;

        return ProductItems::result($active === true ? (string) $body['Id'] : null, $body, $outcome);
    }

    /**
     * One write as a batch answer. A 401 or a rate limit is thrown.
     *
     * @param array<string, mixed> $connection
     * @param Operation            $operation
     *
     * @return Answer
     */
    private function writeOne(array $connection, array $operation): array
    {
        $payload = $operation['payload'];
        try {
            $body = $operation['operation'] === 'create'
                ? $this->client->createItem($connection, $payload)
                : $this->client->updateItem(
                    $connection,
                    (string) ($payload['Id'] ?? ''),
                    (string) ($payload['SyncToken'] ?? '0'),
                    $payload
                );

            return ['ok' => true, 'body' => $body, 'rows' => [], 'error' => null, 'status' => 200, 'halt' => false];
        } catch (QuickBooksException $exception) {
            if ($exception->isUnauthorized() === true || $exception->isRateLimit() === true) {
                throw $exception;
            }

            // A stale SyncToken only means the product changed meanwhile, as in a batch answer.
            return [
                'ok'     => false,
                'body'   => [],
                'rows'   => [],
                'error'  => $exception->getMessage(),
                'status' => $exception->faultCode === self::STALE_TOKEN_FAULT ? 409 : $exception->status,
                'halt'   => false,
                'code'   => (string) $exception->faultCode,
            ];
        }
    }
}
