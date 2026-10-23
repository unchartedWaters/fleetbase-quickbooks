<?php

namespace Fleetbase\Quickbooks\Http\Controllers;

use Fleetbase\Quickbooks\Events\QuickBooksEntityChanged;
use Fleetbase\Quickbooks\Listeners\EnqueueWebhookSync;
use Fleetbase\Quickbooks\Models\Connection;
use Fleetbase\Quickbooks\Models\Link;
use Fleetbase\Quickbooks\Services\SettingsService;
use Fleetbase\Quickbooks\Services\SettingsStore;
use Fleetbase\Quickbooks\Support\SafeLog;
use Fleetbase\Quickbooks\Support\WebhookPayload;
use Fleetbase\Quickbooks\Support\WebhookReplayGuard;
use Fleetbase\Quickbooks\Support\WebhookSignature;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;

/**
 * @phpstan-type WebhookEntity array{
 *     entityType: string, qbo: string, id: string, operation: string, merged_into?: string
 * }
 * @phpstan-type WebhookGroup array{realm: string, entities: array<int, WebhookEntity>}
 */
class WebhookController extends Controller
{
    /** @var array<string, array{type: string, qbo: string}> */
    private const TYPES = [
        'customer' => ['type' => 'customer', 'qbo' => 'Customer'],
        'invoice'  => ['type' => 'invoice', 'qbo' => 'Invoice'],
        'payment'  => ['type' => 'payment', 'qbo' => 'Payment'],
        'account'  => ['type' => 'wallet', 'qbo' => 'Account'],
    ];

    /** @var array<string, string> */
    private const OPERATIONS = [
        'create' => 'create',
        'update' => 'update',
        'delete' => 'delete',
        'void'   => 'delete',
        'merge'  => 'update',
        // The record QuickBooks merged into another one. It is not a delete in Fleetbase.
        'mergedaway' => 'merge',
    ];

    /**
     * Operations a stale delivery does not apply: they would act on the payload alone.
     *
     * @var array<int, string>
     */
    private const STALE_SKIPPED = ['delete', 'merge'];

    private WebhookReplayGuard $replay;

    public function __construct(
        private SettingsService $settings,
        private SettingsStore $store,
        private WebhookSignature $signatures,
    ) {
        $this->replay = new WebhookReplayGuard();
    }

    public function handle(Request $request): JsonResponse
    {
        $rawBody = $request->getContent();
        if (is_string($rawBody) === false) {
            $rawBody = '';
        }

        // Entities are not trusted until the signature matches the install-wide verifier.
        $matched = $this->verifiedConnections($request, $rawBody);
        if ($matched === null) {
            return response()->json(['message' => 'Invalid signature.'], 401);
        }
        // HMAC already matched. A body older than the configured maximum age is stale: its
        // creates and updates only make Fleetbase read the current record from QuickBooks,
        // so they are still used (Intuit can deliver late), but its deletes and merges are
        // not applied from the payload. A body older than the stale replay window is not
        // applied at all. Both are answered with 200: Intuit retries anything else for days
        // and may hold back later deliveries until this one is acknowledged.
        $now   = time();
        $stale = $this->replay->isStale($rawBody, $now);
        if ($stale === true && $this->replay->isExpired($rawBody, $now) === true) {
            SafeLog::warning('QuickBooks webhook delivery is older than the stale replay window and was not applied.');

            return response()->json(['ok' => true]);
        }

        // The signature is genuine, but no organization is connected to these realms
        // (for example after a disconnect). There is nothing to apply. Answer 200 so
        // Intuit does not keep retrying a delivery that can never be used.
        if ($matched === []) {
            return response()->json(['ok' => true]);
        }

        // Remember this exact body and do not apply it twice. A body that was already
        // processed is acknowledged. One that another request is still processing is
        // answered 409 so Intuit retries it, in case that request fails. The check does
        // not replace the signature test. A down replay store is not an invalid signature.
        $replay = $this->replay->remember($rawBody);
        if ($replay === 'done') {
            return response()->json(['ok' => true]);
        }
        if ($replay === 'processing') {
            return response()->json(['message' => 'Webhook delivery is still being processed.'], 409);
        }
        if ($replay === 'unavailable') {
            return response()->json(['message' => 'Webhook delivery could not be recorded.'], 503);
        }

        // The body is remembered only briefly while it is processed. A failure forgets it,
        // and a worker that dies here leaves a key that expires on its own, so Intuit's
        // retry of the identical body is processed and the events are not lost.
        try {
            $this->dispatchEntities($this->entitiesByRealm($rawBody, $stale), $matched);
            app(EnqueueWebhookSync::class)->flush($this->store);
        } catch (\Throwable $exception) {
            $this->replay->forget($rawBody);

            throw $exception;
        }
        // A stale body is remembered for the longer stale replay window, as long as it is accepted.
        $this->replay->keep($rawBody);

        return response()->json(['ok' => true]);
    }

    /**
     * Connections on the delivery's realms when the intuit-signature header matches. Null when
     * the header is missing or the signature does not match. The signature is checked before
     * the body is parsed, and a signed body that names no realm has no connections.
     *
     * @return array<string, array<int, Connection>>|null
     */
    private function verifiedConnections(Request $request, string $rawBody): ?array
    {
        $signature = $request->headers->get('intuit-signature');
        $signature = is_string($signature) === true ? trim($signature) : '';
        if ($signature === '') {
            return null;
        }

        return $this->matchingConnections($rawBody, $signature);
    }

    /**
     * Realm ids from the raw body, used only to choose connections to test.
     *
     * @return array<int, string>
     */
    private function realmIds(string $rawBody): array
    {
        $ids = [];
        foreach (WebhookPayload::realms($rawBody) as $realm) {
            $realmId = $this->stringId($realm);
            if ($realmId !== null) {
                $ids[$realmId] = true;
            }
        }

        return array_map('strval', array_keys($ids));
    }

    /**
     * Connections on the body's realms when the install-wide verifier matches.
     * An organization verifier is not consulted, and a second organization's
     * secret is not a fallback. Null means the signature did not match. An empty
     * array means it matched but the body names no realm with a connection.
     *
     * @return array<string, array<int, Connection>>|null
     */
    private function matchingConnections(string $rawBody, string $signature): ?array
    {
        $verifiers = $this->settings->webhookVerifiersFor($this->store, '');
        if ($this->signatures->accepts($rawBody, $signature, $verifiers) === false) {
            return null;
        }

        $matched = [];
        foreach ($this->realmIds($rawBody) as $realmId) {
            foreach ($this->connectionsForRealm($realmId) as $connection) {
                if ((string) $connection->company_uuid === '') {
                    continue;
                }
                $matched[$realmId][] = $connection;
            }
        }

        return $matched;
    }

    /**
     * The body's changes by realm. A stale body does not apply its deletes and merges: by the
     * time it arrives the record may have been restored or changed again, and reading it
     * back cannot stand in for them (a deleted record and a merged-away one both read as
     * gone, and a voided invoice reads as an invoice with no lines). Intuit reports the
     * surviving record of a merge as an update, so that record is still read. See staleDelete().
     *
     * @return array<int, WebhookGroup>
     */
    private function entitiesByRealm(string $rawBody, bool $stale): array
    {
        $groups  = [];
        $skipped = 0;
        foreach (WebhookPayload::changes($rawBody) as $change) {
            $realmId = $this->stringId($change['realm']);
            if ($realmId === null) {
                continue;
            }
            $mapped = $this->mapEntity($change);
            if ($mapped === null) {
                continue;
            }
            if ($stale === true && in_array($mapped['operation'], self::STALE_SKIPPED, true) === true) {
                $skipped++;
                $mapped = $this->staleDelete($mapped);
                if ($mapped === null) {
                    continue;
                }
            }
            $this->addEntity($groups, $realmId, $mapped);
        }
        if ($stale === true) {
            SafeLog::warning('QuickBooks webhook delivery is older than the maximum age: its records are read again'
                . ' from QuickBooks and its deletes and merges were not applied.', ['skipped' => $skipped]);
        }

        return $groups;
    }

    /**
     * A stale delete is never applied. A payment's becomes a refresh: the invoices it paid have
     * their balance read again from QuickBooks. Any other is passed on only to be noted in
     * Activity. A stale merge is dropped.
     *
     * @param WebhookEntity $mapped
     *
     * @return WebhookEntity|null
     */
    private function staleDelete(array $mapped): ?array
    {
        if ($mapped['operation'] !== 'delete') {
            return null;
        }
        $mapped['operation'] = $mapped['entityType'] === 'payment' ? 'refresh' : 'stale-delete';

        return $mapped;
    }

    /**
     * @param array<string, mixed> $entity
     *
     * @return WebhookEntity|null
     */
    private function mapEntity(array $entity): ?array
    {
        $name = $entity['name'] ?? null;
        if (is_string($name) === false) {
            return null;
        }
        $type = self::TYPES[strtolower($name)] ?? null;
        if ($type === null) {
            return null;
        }
        $id = $this->stringId($entity['id'] ?? null);
        if ($id === null) {
            return null;
        }
        $operation = $entity['operation'] ?? '';
        if (is_string($operation) === false) {
            return null;
        }
        $mapped = self::OPERATIONS[strtolower($operation)] ?? null;
        if ($mapped === null) {
            return null;
        }

        $change = [
            'entityType' => $type['type'],
            'qbo'        => $type['qbo'],
            'id'         => $id,
            'operation'  => $mapped,
        ];
        if ($mapped === 'merge') {
            $into = $this->stringId($entity['merged_into'] ?? null);
            if ($into === null) {
                return null;
            }
            $change['merged_into'] = $into;
        }

        return $change;
    }

    private function stringId(mixed $value): ?string
    {
        if (is_int($value) === true) {
            $value = (string) $value;
        }
        if (is_string($value) === false || $value === '') {
            return null;
        }

        return $value;
    }

    /**
     * @param array<int, WebhookGroup> $groups
     * @param WebhookEntity            $entity
     */
    private function addEntity(array &$groups, string $realmId, array $entity): void
    {
        foreach ($groups as $index => $group) {
            if ($group['realm'] === $realmId) {
                $groups[$index]['entities'][] = $entity;

                return;
            }
        }

        $groups[] = ['realm' => $realmId, 'entities' => [$entity]];
    }

    /**
     * @param array<int, WebhookGroup>              $groups
     * @param array<string, array<int, Connection>> $connectionsByRealm
     */
    private function dispatchEntities(array $groups, array $connectionsByRealm): void
    {
        foreach ($groups as $group) {
            $connections = $connectionsByRealm[$group['realm']] ?? [];
            if ($group['entities'] === [] || $connections === []) {
                continue;
            }
            $ids = [];
            foreach ($group['entities'] as $entity) {
                $ids[] = $entity['id'];
            }
            $localsByCompany = [];
            foreach ($connections as $connection) {
                $companyUuid = (string) $connection->company_uuid;
                if ($companyUuid === '') {
                    continue;
                }
                $localsByCompany[$companyUuid] = $this->localUuids($companyUuid, $group['realm'], $ids);
            }
            // Links belong to the organization that owns the record. A shared
            // connection row only stores one company uuid, so the other
            // organization's invoice is included from the link itself.
            foreach ($this->linksOnRealm($group['realm'], $ids) as $link) {
                $companyUuid = (string) $link->company_uuid;
                if ($companyUuid === '') {
                    continue;
                }
                $qbo = self::TYPES[strtolower((string) $link->qbo_entity)]['qbo'] ?? null;
                if ($qbo === null) {
                    continue;
                }
                $localsByCompany[$companyUuid][$qbo . '|' . (string) $link->qbo_id] = (string) $link->local_uuid;
            }
            foreach ($localsByCompany as $companyUuid => $locals) {
                foreach ($group['entities'] as $entity) {
                    $localUuid = $locals[$entity['qbo'] . '|' . $entity['id']] ?? null;
                    event(new QuickBooksEntityChanged(
                        $companyUuid,
                        $group['realm'],
                        $entity['entityType'],
                        $entity['id'],
                        $entity['operation'],
                        $localUuid,
                        $entity['merged_into'] ?? null
                    ));
                }
            }
        }
    }

    /**
     * Every link for these QuickBooks ids on the realm, whichever organization owns it.
     *
     * @param array<int, string> $quickbooksIds
     *
     * @return array<int, Link>
     */
    protected function linksOnRealm(string $realmId, array $quickbooksIds): array
    {
        if ($quickbooksIds === []) {
            return [];
        }

        $links = [];
        foreach (Link::query()
            ->where('realm_id', $realmId)
            ->whereIn('qbo_id', array_values(array_unique($quickbooksIds)))
            ->get() as $link) {
            if ($link instanceof Link === true) {
                $links[] = $link;
            }
        }

        return $links;
    }

    /**
     * One query for this company. The key is the QuickBooks entity name plus id.
     *
     * @param array<int, string> $quickbooksIds
     *
     * @return array<string, string>
     */
    protected function localUuids(string $companyUuid, string $realmId, array $quickbooksIds): array
    {
        if ($quickbooksIds === []) {
            return [];
        }

        $links = Link::query()
            ->where('company_uuid', $companyUuid)
            ->where('realm_id', $realmId)
            ->whereIn('qbo_id', array_values(array_unique($quickbooksIds)))
            ->get();

        $map = [];
        foreach ($links as $link) {
            if ($link instanceof Link === false) {
                continue;
            }
            $qbo = self::TYPES[strtolower((string) $link->qbo_entity)]['qbo'] ?? null;
            if ($qbo === null) {
                continue;
            }
            $map[$qbo . '|' . (string) $link->qbo_id] = (string) $link->local_uuid;
        }

        return $map;
    }

    /**
     * Every Fleetbase company connected to this QuickBooks realm.
     * Realm is not unique: two companies can share one QuickBooks company.
     *
     * @return array<int, Connection>
     */
    protected function connectionsForRealm(string $realmId): array
    {
        $connections = [];
        foreach (Connection::query()->where('realm_id', $realmId)->get() as $connection) {
            if ($connection instanceof Connection === true) {
                $connections[] = $connection;
            }
        }

        return $connections;
    }
}
