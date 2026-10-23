<?php

namespace Fleetbase\Quickbooks\Services;

use Fleetbase\Quickbooks\Support\ProductMapper;
use Fleetbase\Quickbooks\Support\QuickBooksException;
use Fleetbase\Quickbooks\Support\Translation;

/**
 * Keeps one QuickBooks product (Item) per product name of a company: the Storefront products of
 * that name and the Fleet-Ops fee of that name share it. The sync is one way. A name is matched
 * whatever its case, as QuickBooks matches product names.
 *
 * A product QuickBooks already had under the name is used as it is and never written to
 * ("adopted"). Fleetbase makes a product inactive when no live product uses its name any more
 * ("deactivated"), and active again when the name is used again. A fee is never made inactive.
 *
 * @phpstan-type Result array{
 *     id: string|null, item: array<string, mixed>|null, outcome: string, error: string|null,
 *     transient: bool, note: string|null
 * }
 * @phpstan-type Plan array{
 *     key: string, name: string, products: array<int, array<string, mixed>>, fee: bool, wanted: bool,
 *     link: array<string, mixed>|null, uuid: string
 * }
 * @phpstan-type Operation array{bId: string, operation: string, entity: string, payload: array<string, mixed>}
 * @phpstan-type Write array{write: Operation, link: array<string, mixed>, name: string}
 * @phpstan-type Found array{rows: array<string, array<string, mixed>>, failed: string|null}
 * @phpstan-type Answer array{
 *     ok: bool, body: array<string, mixed>, rows: array<int, array<string, mixed>>, error: string|null,
 *     status: int, halt: bool, code?: string
 * }
 */
class ProductItems
{
    public const ADOPTED = 'adopted';

    public const DEACTIVATED = 'deactivated';

    private const DUPLICATE_NAME_FAULT = '6240';

    private const QUERY_LIMIT = 1000;

    public function __construct(private QuickBooksClient $client, private ProductMapper $mapper = new ProductMapper())
    {
    }

    /**
     * Read, plan and write the QuickBooks product of each name. A 401 or a rate limit is thrown;
     * any other failure is the result of the names it is about.
     *
     * @param array<string, mixed> $connection
     * @param array<int, string>   $keys        product name keys (ProductMapper::key)
     * @param array<string, mixed> $serviceItem the "Fleetbase service" item, read
     *
     * @return array<string, Result> by key
     */
    public function prepare(
        SyncLedger $ledger,
        array $connection,
        array $keys,
        string $serviceItemId,
        array $serviceItem,
    ): array {
        $company = (string) $connection['company_uuid'];
        $realm   = (string) $connection['realm_id'];
        $home    = strtoupper(trim((string) ($connection['home_currency'] ?? '')));
        $plans   = [];
        foreach (array_values(array_unique($keys)) as $key) {
            if ($key === '') {
                continue;
            }
            $plans[$key] = $this->startPlan($ledger, $company, $realm, $key);
        }
        $results = [];
        foreach ($plans as $key => $plan) {
            if ($key === ProductMapper::key(ProductMapper::SERVICE_ITEM_NAME)) {
                $results[$key] = self::result($serviceItemId, $serviceItem, 'aligned');
                unset($plans[$key]);
            } elseif ($plan['fee'] === false && $ledger->productsUnavailable === true) {
                // Storefront could not be read, or only in part: a name's products are not known.
                $results[$key] = self::failed(Translation::encode('quickbooks.messages.products-unavailable'), true);
                unset($plans[$key]);
            } elseif ($plan['wanted'] === false && $plan['link'] === null) {
                $results[$key] = self::result(null, null, 'aligned');
                unset($plans[$key]);
            }
        }
        if ($plans === []) {
            return $results;
        }

        $byId   = $this->readItems($connection, 'Id', array_values(array_filter(array_map(
            static fn (array $plan): string => (string) ($plan['link']['qbo_id'] ?? ''),
            $plans
        ))));
        $names  = [];
        foreach ($plans as $plan) {
            if ($plan['wanted'] === true) {
                $names[] = $plan['name'];
                $names[] = ProductMapper::suffixed($plan['name']);
            }
        }
        $byName = $this->readItems($connection, 'Name', $names);

        /** @var array<string, Write> $writes */
        $writes = [];
        foreach ($plans as $key => $plan) {
            $decided = $this->decide($ledger, $connection, $plan, $byId, $byName, $serviceItemId, $serviceItem, $home);
            if (array_key_exists('write', $decided) === true) {
                $writes[$key] = $decided;
                continue;
            }
            $results[$key] = $decided['result'];
        }

        foreach ($this->write($connection, $writes) as $key => $result) {
            $results[$key] = $result;
        }
        foreach ($results as $key => $result) {
            if (isset($writes[$key]) === true && $result['outcome'] !== 'failed') {
                $ledger->putLink(array_merge($writes[$key]['link'], [
                    'qbo_id'     => (string) ($result['item']['Id'] ?? ''),
                    'sync_token' => (string) ($result['item']['SyncToken'] ?? '0'),
                ]));
            }
        }

        return $results;
    }

    /**
     * The live products of a name, in uuid order: not deleted, not a draft, with a uuid, a name
     * and the company set.
     *
     * @return array<int, array<string, mixed>>
     */
    public static function liveProducts(SyncLedger $ledger, string $companyUuid, string $key): array
    {
        $live = [];
        foreach ($ledger->products as $uuid => $product) {
            $named = ProductMapper::key((string) ($product['name'] ?? '')) === $key;
            if ($named === true && self::isLive($product, $companyUuid) === true) {
                $live[(string) $uuid] = $product;
            }
        }
        ksort($live, SORT_STRING);

        return array_values($live);
    }

    /**
     * @param array<string, mixed> $product
     */
    public static function isLive(array $product, string $companyUuid): bool
    {
        return trim((string) ($product['uuid'] ?? '')) !== ''
            && trim((string) ($product['name'] ?? '')) !== ''
            && (string) ($product['company_uuid'] ?? '') === $companyUuid
            && empty($product['deleted_at']) === true
            && strtolower(trim((string) ($product['status'] ?? ''))) !== 'draft';
    }

    /**
     * @return Plan
     */
    private function startPlan(SyncLedger $ledger, string $company, string $realm, string $key): array
    {
        $uuid     = ProductMapper::itemUuid($company, $key);
        $link     = $ledger->link($company, $realm, 'item', $uuid);
        $products = self::liveProducts($ledger, $company, $key);
        $feeName  = ProductMapper::feeProduct($key);
        $name     = ProductMapper::sharedName($products)
            ?? $feeName
            ?? (is_array($link) === true ? trim((string) ($link['name'] ?? '')) : '');

        return [
            'key'      => $key,
            'name'     => ProductMapper::name($name !== '' ? $name : $key),
            'products' => $products,
            'fee'      => $feeName !== null,
            'wanted'   => $products !== [] || $feeName !== null,
            'link'     => is_array($link) === true && trim((string) ($link['qbo_id'] ?? '')) !== '' ? $link : null,
            'uuid'     => $uuid,
        ];
    }

    /**
     * What to do for one name: a result, or a write.
     *
     * @param array<string, mixed> $connection
     * @param Plan                 $plan
     * @param Found                $byId
     * @param Found                $byName
     * @param array<string, mixed> $serviceItem
     *
     * @return array{result: Result}|Write
     */
    private function decide(
        SyncLedger $ledger,
        array $connection,
        array $plan,
        array $byId,
        array $byName,
        string $serviceItemId,
        array $serviceItem,
        string $home,
    ): array {
        $link   = $plan['link'];
        $remote = null;
        if ($link !== null) {
            if ($byId['failed'] !== null) {
                return ['result' => self::failed($byId['failed'], true)];
            }
            $remote = $byId['rows'][(string) $link['qbo_id']] ?? null;
        }
        $state = $link === null ? null : ($link['state'] ?? null);

        if (is_array($remote) === true) {
            $active = ($remote['Active'] ?? true) !== false;
            if ($state === self::ADOPTED) {
                return ['result' => $active === true
                    ? self::result((string) $remote['Id'], $remote, 'aligned')
                    : self::result(null, $remote, 'aligned', $this->inactiveNote($plan['name']))];
            }
            if ($active === false) {
                if ($plan['wanted'] === false) {
                    return ['result' => self::result(null, $remote, 'aligned')];
                }
                if ($state !== self::DEACTIVATED) {
                    // Made inactive in QuickBooks: that choice stays, and lines use the service item.
                    return ['result' => self::result(null, $remote, 'aligned', $this->inactiveNote($plan['name']))];
                }
                $holder = $this->activeNamed($byName, $plan['name'], (string) $remote['Id']);
                if ($holder === null) {
                    $name = $this->keepsSuffix($remote) === true
                        ? ProductMapper::suffixed($plan['name'])
                        : $plan['name'];

                    return $this->update($connection, $plan, $remote, ['Active' => true, 'Name' => $name], null);
                }
                // The name went to another QuickBooks product while ours was inactive.
                $remote = null;
                $link   = null;
            } elseif ($plan['wanted'] === false) {
                // Without Storefront tables no product is known gone, so none is made inactive.
                $kept = $plan['fee'] === true || (string) $remote['Id'] === $serviceItemId;
                if ($kept === true || $ledger->productsMissing === true) {
                    return ['result' => self::result((string) $remote['Id'], $remote, 'aligned')];
                }

                return $this->update($connection, $plan, $remote, ['Active' => false], self::DEACTIVATED);
            } else {
                $changes = $this->mapper->changes($plan['name'], $plan['products'], $remote, $home);
                if ($changes === []) {
                    return ['result' => self::result((string) $remote['Id'], $remote, 'aligned')];
                }

                return $this->update($connection, $plan, $remote, $changes, null);
            }
        }

        if ($plan['wanted'] === false) {
            return ['result' => self::result(null, null, 'aligned')];
        }
        if ($byName['failed'] !== null) {
            return ['result' => self::failed($byName['failed'], true)];
        }

        $realm = (string) $connection['realm_id'];
        $found = $this->named($byName, $plan['name']);
        if ($found !== null && $this->adoptable($ledger, $realm, $plan, $found, $serviceItemId) === true) {
            $ledger->putLink($this->link($connection, $plan, $found, self::ADOPTED));
            $note = Translation::encode(
                'quickbooks.messages.product-adopted',
                ['name' => (string) ($found['Name'] ?? '')]
            );

            return ['result' => self::result((string) $found['Id'], $found, 'aligned', $note)];
        }
        $name = $plan['name'];
        if ($found !== null) {
            $name    = ProductMapper::suffixed($plan['name']);
            $ours    = $this->named($byName, $name);
            $usable  = $ours !== null
                && ($ours['Active'] ?? true) !== false
                && $this->linkedElsewhere($ledger, $realm, $plan, (string) $ours['Id']) === false;
            if ($usable === true) {
                // A product Fleetbase made under the suffixed name before its link was lost.
                $ledger->putLink($this->link($connection, $plan, $ours, null));
                $changes = $this->mapper->changes($plan['name'], $plan['products'], $ours, $home);
                if ($changes === []) {
                    return ['result' => self::result((string) $ours['Id'], $ours, 'aligned')];
                }

                return $this->update($connection, $plan, $ours, $changes, null);
            }
            if ($ours !== null) {
                return ['result' => self::failed($this->nameTaken($name), false)];
            }
        }
        $payload = $this->mapper->create($name, $plan['products'], $home, $serviceItem);

        return [
            'write' => [
                'bId'       => 'item:' . $plan['uuid'],
                'operation' => 'create',
                'entity'    => 'Item',
                'payload'   => $payload,
            ],
            'link'  => $this->link($connection, $plan, null, null),
            'name'  => $name,
        ];
    }

    /**
     * @param array<string, mixed> $connection
     * @param Plan                 $plan
     * @param array<string, mixed> $remote
     * @param array<string, mixed> $changes
     *
     * @return Write
     */
    private function update(array $connection, array $plan, array $remote, array $changes, ?string $state): array
    {
        $payload = $changes + [
            'Id'        => (string) $remote['Id'],
            'SyncToken' => (string) ($remote['SyncToken'] ?? '0'),
            'sparse'    => true,
        ];

        return [
            'write' => [
                'bId'       => 'item:' . $plan['uuid'],
                'operation' => 'update',
                'entity'    => 'Item',
                'payload'   => $payload,
            ],
            'link'  => $this->link($connection, $plan, $remote, $state),
            'name'  => (string) ($changes['Name'] ?? $remote['Name'] ?? $plan['name']),
        ];
    }

    /**
     * Send the writes, one call for one write, else the Batch API.
     *
     * @param array<string, mixed> $connection
     * @param array<string, Write> $writes     by key
     *
     * @return array<string, Result> by key
     */
    private function write(array $connection, array $writes): array
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
            $answer = $answers[$write['write']['bId']] ?? null;
            if ($answer !== null && $answer['halt'] === true) {
                throw new QuickBooksException($answer['status'], (string) $answer['error']);
            }
            $body = $answer['body'] ?? [];
            if ($answer === null || $answer['ok'] === false || trim((string) ($body['Id'] ?? '')) === '') {
                $status        = $answer['status'] ?? 400;
                $error         = ($answer['code'] ?? '') === self::DUPLICATE_NAME_FAULT
                    ? $this->nameTaken($write['name'])
                    : (string) ($answer['error'] ?? 'quickbooks.messages.batch-item-missing');
                $results[$key] = self::failed($error, (new QuickBooksException($status, $error))->isTransient());
                continue;
            }
            $outcome       = $write['write']['operation'] === 'create' ? 'created' : 'updated';
            $active        = ($body['Active'] ?? true) !== false;
            $results[$key] = self::result($active === true ? (string) $body['Id'] : null, $body, $outcome);
        }

        return $results;
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
                'status' => $exception->faultCode === '5010' ? 409 : $exception->status,
                'halt'   => false,
                'code'   => (string) $exception->faultCode,
            ];
        }
    }

    /**
     * Read QuickBooks products by Id or Name, active and inactive, in one batch of queries.
     *
     * @param array<string, mixed> $connection
     * @param array<int, string>   $values
     *
     * @return Found rows by Id
     */
    private function readItems(array $connection, string $field, array $values): array
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
                $id = trim((string) ($row['Id'] ?? ''));
                if ($id !== '') {
                    $rows[$id] = $row;
                }
            }
        }

        return ['rows' => $rows, 'failed' => null];
    }

    /**
     * @param Found $byName
     *
     * @return array<string, mixed>|null
     */
    private function named(array $byName, string $name): ?array
    {
        $key = mb_strtolower(trim($name));
        foreach ($byName['rows'] as $row) {
            if (mb_strtolower(trim((string) ($row['Name'] ?? ''))) === $key) {
                return $row;
            }
        }

        return null;
    }

    /**
     * @param Found $byName
     *
     * @return array<string, mixed>|null
     */
    private function activeNamed(array $byName, string $name, string $exceptId): ?array
    {
        $found = $this->named($byName, $name);

        return is_array($found) === true
            && (string) ($found['Id'] ?? '') !== $exceptId
            && ($found['Active'] ?? true) !== false
            ? $found
            : null;
    }

    /**
     * A QuickBooks product Fleetbase may use as it is: active, a service or non-inventory
     * product, not a sub-product, not the service item, and no other record's product. A fee
     * may share one with the fee of another organization on the same QuickBooks company.
     *
     * @param Plan                 $plan
     * @param array<string, mixed> $remote
     */
    private function adoptable(
        SyncLedger $ledger,
        string $realm,
        array $plan,
        array $remote,
        string $serviceItemId,
    ): bool {
        $id = (string) ($remote['Id'] ?? '');
        if ($id === '' || $id === $serviceItemId || ($remote['Active'] ?? true) === false) {
            return false;
        }
        if (in_array((string) ($remote['Type'] ?? ''), ['Service', 'NonInventory'], true) === false) {
            return false;
        }
        if (($remote['SubItem'] ?? false) === true || isset($remote['ParentRef']) === true) {
            return false;
        }

        return $plan['fee'] === true || $this->linkedElsewhere($ledger, $realm, $plan, $id) === false;
    }

    /**
     * @param Plan $plan
     */
    private function linkedElsewhere(SyncLedger $ledger, string $realm, array $plan, string $id): bool
    {
        return $ledger->remoteIdsLinked($realm, 'Item', [$id], (string) $plan['uuid']) !== [];
    }

    /**
     * @param array<string, mixed> $remote
     */
    private function keepsSuffix(array $remote): bool
    {
        $name = preg_replace('/ \(deleted\)$/u', '', trim((string) ($remote['Name'] ?? ''))) ?? '';

        return str_ends_with($name, ' (' . ProductMapper::SUFFIX . ')');
    }

    /**
     * @param array<string, mixed>      $connection
     * @param Plan                      $plan
     * @param array<string, mixed>|null $remote
     *
     * @return array<string, mixed>
     */
    private function link(array $connection, array $plan, ?array $remote, ?string $state): array
    {
        $link = [
            'company_uuid' => (string) $connection['company_uuid'],
            'realm_id'     => (string) $connection['realm_id'],
            'local_type'   => 'item',
            'local_uuid'   => (string) $plan['uuid'],
            'qbo_entity'   => 'Item',
            'name'         => (string) $plan['name'],
            'state'        => $state,
        ];
        if ($remote !== null) {
            $link['qbo_id']     = (string) ($remote['Id'] ?? '');
            $link['sync_token'] = (string) ($remote['SyncToken'] ?? '0');
        }

        return $link;
    }

    private function inactiveNote(string $name): string
    {
        return Translation::encode('quickbooks.messages.product-inactive', ['name' => $name]);
    }

    private function nameTaken(string $name): string
    {
        return Translation::encode('quickbooks.messages.product-name-taken', ['name' => $name]);
    }

    /**
     * @param array<string, mixed>|null $item
     *
     * @return Result
     */
    private static function result(?string $id, ?array $item, string $outcome, ?string $note = null): array
    {
        return [
            'id'        => $id,
            'item'      => $item,
            'outcome'   => $outcome,
            'error'     => null,
            'transient' => false,
            'note'      => $note,
        ];
    }

    /**
     * @return Result
     */
    private static function failed(string $error, bool $transient): array
    {
        return [
            'id'        => null,
            'item'      => null,
            'outcome'   => 'failed',
            'error'     => $error,
            'transient' => $transient,
            'note'      => null,
        ];
    }
}
