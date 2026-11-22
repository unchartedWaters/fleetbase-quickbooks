<?php

use Fleetbase\Quickbooks\Services\SyncEngine;
use Fleetbase\Quickbooks\Tests\Support\FakeQuickBooks;

/*
 * queryWhereIn pages a chunk of values with startposition. Each page here is scripted by
 * its start position: a list of rows, or null for a refused page.
 */
$whereIn = static function (array $pages, array $values): array {
    $client = new class extends FakeQuickBooks {
        /** @var array<int, array<int, array<string, mixed>>|null> */
        public array $pages = [];

        public function batch(array $connection, array $items): array
        {
            $item  = $items[0];
            $start = preg_match('/startposition (\d+)/', (string) $item['query'], $match) === 1 ? (int) $match[1] : 1;
            $rows  = array_key_exists($start, $this->pages) === true ? $this->pages[$start] : [];
            $ok    = $rows !== null;

            return [(string) $item['bId'] => [
                'ok'     => $ok,
                'body'   => [],
                'rows'   => $rows ?? [],
                'error'  => $ok === true ? null : 'refused',
                'status' => $ok === true ? 200 : 400,
                'halt'   => false,
            ]];
        }
    };
    $client->pages = $pages;
    [$engine]      = qbEngine($client);
    $query         = new ReflectionMethod(SyncEngine::class, 'queryWhereIn');

    $readOne       = static fn (): ?array => null;

    return $query->invoke($engine, ['company_uuid' => 'company-uuid'], 'Invoice', 'DocNumber', $values, $readOne);
};

$fullPage = static function (string $doc): array {
    $rows = [['Id' => 'qb-' . $doc, 'DocNumber' => $doc]];
    for ($i = 1; $i < 100; $i++) {
        $rows[] = ['Id' => 'other-' . $i, 'DocNumber' => 'OTHER-' . $i];
    }

    return $rows;
};

test('a refused second page fails only the values the first page did not match', function () use ($whereIn, $fullPage) {
    $found = $whereIn([1 => $fullPage('A'), 101 => null], ['A', 'B', 'C']);

    expect($found['rows'])->toHaveCount(100)
        ->and(array_keys($found['failed']))->toBe(['B', 'C']);
});

test('a page that repeats rows already read adds nothing and ends the paging', function () use ($whereIn, $fullPage) {
    $pages = [1 => $fullPage('A'), 101 => $fullPage('A'), 201 => [['Id' => 'late', 'DocNumber' => 'B']]];
    $found = $whereIn($pages, ['A', 'B']);

    expect($found['rows'])->toHaveCount(100)
        ->and($found['failed'])->toBe([]);
});
