<?php

namespace Fleetbase\Quickbooks\Services;

use Fleetbase\Quickbooks\Support\ProductMapper;
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
 * @phpstan-type Decision array{result: Result}|Write
 */
class ProductItems
{
    public const ADOPTED = 'adopted';

    public const DEACTIVATED = 'deactivated';

    private ProductItemCalls $calls;

    private SyncLedger $ledger;

    /** @var array<string, mixed> */
    private array $connection = [];

    private string $serviceItemId = '';

    /** @var array<string, mixed> */
    private array $serviceItem = [];

    private string $home = '';

    /** @var Found */
    private array $byName = ['rows' => [], 'failed' => null];

    public function __construct(QuickBooksClient $client, private ProductMapper $mapper = new ProductMapper())
    {
        $this->calls = new ProductItemCalls($client);
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
        $this->ledger        = $ledger;
        $this->connection    = $connection;
        $this->serviceItemId = $serviceItemId;
        $this->serviceItem   = $serviceItem;
        $this->home          = strtoupper(trim((string) ($connection['home_currency'] ?? '')));
        $results             = [];
        $plans               = $this->plans($keys, $results);
        if ($plans === []) {
            return $results;
        }

        $byId         = $this->calls->read($connection, 'Id', array_values(array_filter(array_map(
            static fn (array $plan): string => (string) ($plan['link']['qbo_id'] ?? ''),
            $plans
        ))));
        $this->byName = $this->calls->read($connection, 'Name', $this->wantedNames($plans));

        /** @var array<string, Write> $writes */
        $writes = [];
        foreach ($plans as $key => $plan) {
            $decided = $this->decide($plan, $byId);
            if (array_key_exists('write', $decided) === true) {
                $writes[$key] = $decided;
                continue;
            }
            $results[$key] = $decided['result'];
        }
        foreach ($this->calls->write($connection, $writes) as $key => $result) {
            $results[$key] = $result;
            if ($result['outcome'] !== 'failed') {
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
     * The plan of each name to read QuickBooks for. A name settled without QuickBooks gets its
     * result: the service item's name, a name whose products could not be read, and a name no
     * product uses that has no QuickBooks product.
     *
     * @param array<int, string>    $keys
     * @param array<string, Result> $results
     *
     * @return array<string, Plan> by key
     */
    private function plans(array $keys, array &$results): array
    {
        $company = (string) $this->connection['company_uuid'];
        $realm   = (string) $this->connection['realm_id'];
        $plans   = [];
        foreach (array_values(array_unique($keys)) as $key) {
            if ($key === '') {
                continue;
            }
            $plan = $this->startPlan($this->ledger, $company, $realm, $key);
            if ($key === ProductMapper::key(ProductMapper::SERVICE_ITEM_NAME)) {
                $results[$key] = self::result($this->serviceItemId, $this->serviceItem, 'aligned');
            } elseif ($plan['fee'] === false && $this->ledger->productsUnavailable === true) {
                // Storefront could not be read, or only in part: a name's products are not known.
                $results[$key] = self::failed(Translation::encode('quickbooks.messages.products-unavailable'), true);
            } elseif ($plan['wanted'] === false && $plan['link'] === null) {
                $results[$key] = self::result(null, null, 'aligned');
            } else {
                $plans[$key] = $plan;
            }
        }

        return $plans;
    }

    /**
     * Each name in use, and its suffixed form, to look up by name.
     *
     * @param array<string, Plan> $plans
     *
     * @return array<int, string>
     */
    private function wantedNames(array $plans): array
    {
        $names = [];
        foreach ($plans as $plan) {
            if ($plan['wanted'] === true) {
                $names[] = $plan['name'];
                $names[] = ProductMapper::suffixed($plan['name']);
            }
        }

        return $names;
    }

    /**
     * What to do for one name: a result, or a write.
     *
     * @param Plan  $plan
     * @param Found $byId
     *
     * @return Decision
     */
    private function decide(array $plan, array $byId): array
    {
        $link = $plan['link'];
        if ($link !== null) {
            if ($byId['failed'] !== null) {
                return ['result' => self::failed($byId['failed'], true)];
            }
            $remote  = $byId['rows'][(string) $link['qbo_id']] ?? null;
            $state   = is_string($link['state'] ?? null) === true ? $link['state'] : null;
            $decided = is_array($remote) === true ? $this->decideLinked($plan, $remote, $state) : null;
            if ($decided !== null) {
                return $decided;
            }
        }

        return $this->decideUnlinked($plan);
    }

    /**
     * A name whose QuickBooks product was found. Null when the name has to be placed again: its
     * product, made inactive by Fleetbase, lost the name to another product meanwhile.
     *
     * @param Plan                 $plan
     * @param array<string, mixed> $remote
     *
     * @return Decision|null
     */
    private function decideLinked(array $plan, array $remote, ?string $state): ?array
    {
        $active = ($remote['Active'] ?? true) !== false;
        if ($state === self::ADOPTED) {
            return ['result' => $active === true
                ? self::result((string) $remote['Id'], $remote, 'aligned')
                : self::result(null, $remote, 'aligned', $this->inactiveNote($plan['name']))];
        }
        if ($active === false) {
            return $this->decideInactive($plan, $remote, $state);
        }
        if ($plan['wanted'] === false) {
            // Without Storefront tables no product is known gone, so none is made inactive.
            $kept = $plan['fee'] === true || (string) $remote['Id'] === $this->serviceItemId;
            if ($kept === true || $this->ledger->productsMissing === true) {
                return ['result' => self::result((string) $remote['Id'], $remote, 'aligned')];
            }

            return $this->update($plan, $remote, ['Active' => false], self::DEACTIVATED);
        }

        return $this->updated($plan, $remote);
    }

    /**
     * @param Plan                 $plan
     * @param array<string, mixed> $remote
     *
     * @return Decision|null
     */
    private function decideInactive(array $plan, array $remote, ?string $state): ?array
    {
        if ($plan['wanted'] === false) {
            return ['result' => self::result(null, $remote, 'aligned')];
        }
        if ($state !== self::DEACTIVATED) {
            // Made inactive in QuickBooks: that choice stays, and lines use the service item.
            return ['result' => self::result(null, $remote, 'aligned', $this->inactiveNote($plan['name']))];
        }
        if ($this->activeNamed($plan['name'], (string) $remote['Id']) !== null) {
            return null;
        }
        $name = $this->keepsSuffix($remote) === true ? ProductMapper::suffixed($plan['name']) : $plan['name'];

        return $this->update($plan, $remote, ['Active' => true, 'Name' => $name], null);
    }

    /**
     * A name in use with no QuickBooks product of its own: adopt the product QuickBooks has
     * under the name, else take back one Fleetbase made under the suffixed name, else create.
     *
     * @param Plan $plan
     *
     * @return Decision
     */
    private function decideUnlinked(array $plan): array
    {
        if ($plan['wanted'] === false) {
            return ['result' => self::result(null, null, 'aligned')];
        }
        if ($this->byName['failed'] !== null) {
            return ['result' => self::failed($this->byName['failed'], true)];
        }

        $found = $this->named($plan['name']);
        if ($found !== null && $this->adoptable($plan, $found) === true) {
            $this->ledger->putLink($this->link($plan, $found, self::ADOPTED));
            $note = Translation::encode(
                'quickbooks.messages.product-adopted',
                ['name' => (string) ($found['Name'] ?? '')]
            );

            return ['result' => self::result((string) $found['Id'], $found, 'aligned', $note)];
        }
        if ($found === null) {
            return $this->create($plan, $plan['name']);
        }
        $name = ProductMapper::suffixed($plan['name']);
        $ours = $this->named($name);
        if ($ours === null) {
            return $this->create($plan, $name);
        }
        $usable = ($ours['Active'] ?? true) !== false && $this->linkedElsewhere($plan, (string) $ours['Id']) === false;
        if ($usable === false) {
            return ['result' => self::failed(self::nameTaken($name), false)];
        }
        // A product Fleetbase made under the suffixed name before its link was lost.
        $this->ledger->putLink($this->link($plan, $ours, null));

        return $this->updated($plan, $ours);
    }

    /**
     * The update that makes a product show the Fleetbase products, or aligned when it does.
     *
     * @param Plan                 $plan
     * @param array<string, mixed> $remote
     *
     * @return Decision
     */
    private function updated(array $plan, array $remote): array
    {
        $changes = $this->mapper->changes($plan['name'], $plan['products'], $remote, $this->home);
        if ($changes === []) {
            return ['result' => self::result((string) $remote['Id'], $remote, 'aligned')];
        }

        return $this->update($plan, $remote, $changes, null);
    }

    /**
     * @param Plan $plan
     *
     * @return Write
     */
    private function create(array $plan, string $name): array
    {
        return [
            'write' => [
                'bId'       => 'item:' . $plan['uuid'],
                'operation' => 'create',
                'entity'    => 'Item',
                'payload'   => $this->mapper->create($name, $plan['products'], $this->home, $this->serviceItem),
            ],
            'link'  => $this->link($plan, null, null),
            'name'  => $name,
        ];
    }

    /**
     * @param Plan                 $plan
     * @param array<string, mixed> $remote
     * @param array<string, mixed> $changes
     *
     * @return Write
     */
    private function update(array $plan, array $remote, array $changes, ?string $state): array
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
            'link'  => $this->link($plan, $remote, $state),
            'name'  => (string) ($changes['Name'] ?? $remote['Name'] ?? $plan['name']),
        ];
    }

    /**
     * The top-level QuickBooks product with the name, whatever the case. A Name query also
     * returns sub-products by their own name, and those do not hold the name (checked in the
     * sandbox).
     *
     * @return array<string, mixed>|null
     */
    private function named(string $name): ?array
    {
        $key = mb_strtolower(trim($name));
        foreach ($this->byName['rows'] as $row) {
            if (($row['SubItem'] ?? false) === true || isset($row['ParentRef']) === true) {
                continue;
            }
            if (mb_strtolower(trim((string) ($row['Name'] ?? ''))) === $key) {
                return $row;
            }
        }

        return null;
    }

    /**
     * @return array<string, mixed>|null
     */
    private function activeNamed(string $name, string $exceptId): ?array
    {
        $found = $this->named($name);

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
    private function adoptable(array $plan, array $remote): bool
    {
        $itemId = (string) ($remote['Id'] ?? '');
        if ($itemId === '' || $itemId === $this->serviceItemId || ($remote['Active'] ?? true) === false) {
            return false;
        }
        if (in_array((string) ($remote['Type'] ?? ''), ['Service', 'NonInventory'], true) === false) {
            return false;
        }
        if (($remote['SubItem'] ?? false) === true || isset($remote['ParentRef']) === true) {
            return false;
        }

        return $plan['fee'] === true || $this->linkedElsewhere($plan, $itemId) === false;
    }

    /**
     * @param Plan $plan
     */
    private function linkedElsewhere(array $plan, string $itemId): bool
    {
        $realm = (string) $this->connection['realm_id'];

        return $this->ledger->remoteIdsLinked($realm, 'Item', [$itemId], (string) $plan['uuid']) !== [];
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
     * @param Plan                      $plan
     * @param array<string, mixed>|null $remote
     *
     * @return array<string, mixed>
     */
    private function link(array $plan, ?array $remote, ?string $state): array
    {
        $link = [
            'company_uuid' => (string) $this->connection['company_uuid'],
            'realm_id'     => (string) $this->connection['realm_id'],
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

    public static function nameTaken(string $name): string
    {
        return Translation::encode('quickbooks.messages.product-name-taken', ['name' => $name]);
    }

    /**
     * @param array<string, mixed>|null $item
     *
     * @return Result
     */
    public static function result(?string $itemId, ?array $item, string $outcome, ?string $note = null): array
    {
        return [
            'id'        => $itemId,
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
    public static function failed(string $error, bool $transient): array
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
