<?php

namespace Fleetbase\Quickbooks\Tests\Support;

use Fleetbase\Quickbooks\Services\QuickBooksClient;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;

/**
 * A FakeQuickBooks whose Batch API calls go through the real QuickBooksClient::batch()
 * over a faked HTTP endpoint. Each batch POST is answered from the fake's own records,
 * except the write chunk numbered $failWriteChunk, which gets HTTP $failStatus once.
 */
class ChunkFaultQuickBooks extends FakeQuickBooks
{
    public int $failWriteChunk = 0;

    public int $failStatus = 503;

    public int $writeChunks = 0;

    /** @var array<string, mixed>|null */
    private ?array $fakedFor = null;

    /**
     * @param array<string, mixed>                                                                                                $connection
     * @param array<int, array{bId: string, operation?: string, entity?: string, payload?: array<string, mixed>, query?: string}> $items
     *
     * @return array<string, array{ok: bool, body: array<string, mixed>, rows: array<int, array<string, mixed>>, error: string|null, status: int, halt: bool}>
     */
    public function batch(array $connection, array $items): array
    {
        $this->calls[] = 'batch';
        if ($this->fakedFor === null) {
            Http::fake(['*' => fn (Request $request) => $this->answer((array) $this->fakedFor, $request)]);
        }
        $this->fakedFor = $connection;

        return QuickBooksClient::batch($connection, $items);
    }

    /**
     * @param array<string, mixed> $connection
     */
    private function answer(array $connection, Request $request): \GuzzleHttp\Promise\PromiseInterface
    {
        $items  = self::itemsFrom($request->data()['BatchItemRequest'] ?? []);
        $writes = array_filter($items, static fn (array $item): bool => isset($item['operation']) === true);
        if ($writes !== []) {
            $this->writeChunks++;
            if ($this->writeChunks === $this->failWriteChunk) {
                $this->failWriteChunk = 0;

                return Http::response(['Fault' => ['Error' => [['Message' => 'Throttled', 'code' => '003001']]]], $this->failStatus);
            }
        }

        $rows = [];
        foreach (parent::batch($connection, $items) as $bId => $result) {
            $rows[] = self::responseRow((string) $bId, $result, $items);
        }

        return Http::response(['BatchItemResponse' => $rows], 200);
    }

    /**
     * @param array<int, array<string, mixed>> $requests
     *
     * @return array<int, array<string, mixed>>
     */
    private static function itemsFrom(array $requests): array
    {
        $items = [];
        foreach ($requests as $request) {
            if (isset($request['Query']) === true) {
                $items[] = ['bId' => $request['bId'], 'query' => $request['Query']];
                continue;
            }
            foreach ($request as $key => $value) {
                if ($key !== 'bId' && $key !== 'operation' && is_array($value) === true) {
                    $items[] = ['bId' => $request['bId'], 'operation' => $request['operation'], 'entity' => $key, 'payload' => $value];
                }
            }
        }

        return $items;
    }

    /**
     * @param array<string, mixed>             $result
     * @param array<int, array<string, mixed>> $items
     *
     * @return array<string, mixed>
     */
    private static function responseRow(string $bId, array $result, array $items): array
    {
        if ($result['ok'] === false) {
            return ['bId' => $bId, 'Fault' => ['Error' => [['Detail' => (string) $result['error'], 'code' => '6000']]]];
        }
        foreach ($items as $item) {
            if ($item['bId'] === $bId && isset($item['entity']) === true) {
                return ['bId' => $bId, (string) $item['entity'] => $result['body']];
            }
        }

        return ['bId' => $bId, 'QueryResponse' => ['Entity' => $result['rows']]];
    }
}
