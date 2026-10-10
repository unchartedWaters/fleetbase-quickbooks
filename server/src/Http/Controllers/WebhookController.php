<?php

namespace Fleetbase\Quickbooks\Http\Controllers;

use Fleetbase\Quickbooks\Events\QuickBooksEntityChanged;
use Fleetbase\Quickbooks\Listeners\EnqueueWebhookSync;
use Fleetbase\Quickbooks\Models\Connection;
use Fleetbase\Quickbooks\Models\Link;
use Fleetbase\Quickbooks\Services\SettingsService;
use Fleetbase\Quickbooks\Services\SettingsStore;
use Fleetbase\Quickbooks\Support\WebhookReplayGuard;
use Fleetbase\Quickbooks\Support\WebhookSignature;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;

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
    ];

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
        // HMAC already matched. A captured body is not accepted once its
        // entity timestamps are older than the configured maximum age.
        if ($this->replay->isStale($rawBody, time()) === true) {
            return response()->json(['message' => 'Invalid signature.'], 401);
        }

        // The signature is genuine, but no organization is connected to these realms
        // (for example after a disconnect). There is nothing to apply. Answer 200 so
        // Intuit does not keep retrying a delivery that can never be used.
        if ($matched === []) {
            return response()->json(['ok' => true]);
        }

        // Remember this exact body for a short time and reject a second delivery
        // of it. The check does not replace the signature test. A down replay
        // store is not an invalid signature.
        $replay = $this->replay->remember($rawBody);
        if ($replay === 'replay') {
            return response()->json(['message' => 'Invalid signature.'], 401);
        }
        if ($replay === 'unavailable') {
            return response()->json(['message' => 'Webhook delivery could not be recorded.'], 503);
        }

        // The body is remembered only briefly while it is processed. A failure forgets it,
        // and a worker that dies here leaves a key that expires on its own, so Intuit's
        // retry of the identical body is processed and the events are not lost.
        try {
            $this->dispatchEntities($this->entitiesByRealm($rawBody), $matched);
            app(EnqueueWebhookSync::class)->flush($this->store);
        } catch (\Throwable $exception) {
            $this->replay->forget($rawBody);

            throw $exception;
        }
        $this->replay->keep($rawBody);

        return response()->json(['ok' => true]);
    }

    /**
     * Connections on the delivery's realms when the intuit-signature header matches. Null when
     * the header is missing, the body names no realm, or the signature does not match.
     *
     * @return array<string, array<int, Connection>>|null
     */
    private function verifiedConnections(Request $request, string $rawBody): ?array
    {
        // Realm ids choose which connections to apply.
        $signature = $request->headers->get('intuit-signature');
        $signature = is_string($signature) === true ? trim($signature) : '';
        if ($signature === '') {
            return null;
        }

        $realmIds = $this->realmIds($rawBody);
        if ($realmIds === []) {
            return null;
        }

        return $this->matchingConnections($rawBody, $signature, $realmIds);
    }

    /**
     * Realm ids from the raw body, used only to choose connections to test.
     *
     * @return array<int, string>
     */
    private function realmIds(string $rawBody): array
    {
        $decoded = json_decode($rawBody, true);
        if (is_array($decoded) === false) {
            return [];
        }
        $notifications = $decoded['eventNotifications'] ?? null;
        if (is_array($notifications) === false) {
            return [];
        }

        $ids = [];
        foreach ($notifications as $notification) {
            if (is_array($notification) === false) {
                continue;
            }
            $realmId = $this->stringId($notification['realmId'] ?? null);
            if ($realmId === null || in_array($realmId, $ids, true) === true) {
                continue;
            }
            $ids[] = $realmId;
        }

        return $ids;
    }

    /**
     * Connections on the named realms when the install-wide verifier matches.
     * An organization verifier is not consulted, and a second organization's
     * secret is not a fallback. Null means the signature did not match. An empty
     * array means it matched but no organization is connected to those realms.
     *
     * @param array<int, string> $realmIds
     *
     * @return array<string, array<int, Connection>>|null
     */
    private function matchingConnections(string $rawBody, string $signature, array $realmIds): ?array
    {
        $verifiers = $this->settings->webhookVerifiersFor($this->store, '');
        if ($this->signatures->accepts($rawBody, $signature, $verifiers) === false) {
            return null;
        }

        $matched = [];
        foreach ($realmIds as $realmId) {
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
     * @return array<int, array{realm: string, entities: array<int, array{entityType: string, qbo: string, id: string, operation: string}>}>
     */
    private function entitiesByRealm(string $rawBody): array
    {
        $decoded = json_decode($rawBody, true);
        if (is_array($decoded) === false) {
            return [];
        }
        $notifications = $decoded['eventNotifications'] ?? null;
        if (is_array($notifications) === false) {
            return [];
        }

        $groups = [];
        foreach ($notifications as $notification) {
            if (is_array($notification) === false) {
                continue;
            }
            $realmId = $this->stringId($notification['realmId'] ?? null);
            if ($realmId === null) {
                continue;
            }
            $change   = $notification['dataChangeEvent'] ?? null;
            $entities = is_array($change) === true ? ($change['entities'] ?? null) : null;
            if (is_array($entities) === false) {
                continue;
            }
            foreach ($entities as $entity) {
                if (is_array($entity) === false) {
                    continue;
                }
                $mapped = $this->mapEntity($entity);
                if ($mapped === null) {
                    continue;
                }
                $this->addEntity($groups, $realmId, $mapped);
            }
        }

        return $groups;
    }

    /**
     * @param array<string, mixed> $entity
     *
     * @return array{entityType: string, qbo: string, id: string, operation: string}|null
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

        return [
            'entityType' => $type['type'],
            'qbo'        => $type['qbo'],
            'id'         => $id,
            'operation'  => $mapped,
        ];
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
     * @param array<int, array{realm: string, entities: array<int, array{entityType: string, qbo: string, id: string, operation: string}>}> $groups
     * @param array{entityType: string, qbo: string, id: string, operation: string}                                                         $entity
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
     * @param array<int, array{realm: string, entities: array<int, array{entityType: string, qbo: string, id: string, operation: string}>}> $groups
     * @param array<string, array<int, Connection>>                                                                                         $connectionsByRealm
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
                        $localUuid
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
