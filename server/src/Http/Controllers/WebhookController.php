<?php

namespace Fleetbase\Quickbooks\Http\Controllers;

use Fleetbase\Quickbooks\Events\QuickBooksEntityChanged;
use Fleetbase\Quickbooks\Listeners\EnqueueWebhookSync;
use Fleetbase\Quickbooks\Models\Connection;
use Fleetbase\Quickbooks\Models\Link;
use Fleetbase\Quickbooks\Services\SettingsService;
use Fleetbase\Quickbooks\Services\SettingsStore;
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

    public function __construct(
        private SettingsService $settings,
        private SettingsStore $store,
        private WebhookSignature $signatures,
    ) {
    }

    public function handle(Request $request): JsonResponse
    {
        $rawBody = $request->getContent();
        if (!is_string($rawBody)) {
            $rawBody = '';
        }

        // The signature is checked per connection. Realm ids choose which
        // connections to test. Entities are not trusted until a verifier matches.
        $signature = $request->headers->get('intuit-signature');
        if (is_string($signature)) {
            $signature = trim($signature);
        }
        if (!is_string($signature) || $signature === '') {
            return response()->json(['message' => 'Invalid signature.'], 401);
        }

        $realmIds = $this->realmIds($rawBody);
        if ($realmIds === []) {
            return response()->json(['message' => 'Invalid signature.'], 401);
        }

        $matched = $this->matchingConnections($rawBody, $signature, $realmIds);
        if ($matched === []) {
            return response()->json(['message' => 'Invalid signature.'], 401);
        }

        $this->dispatchEntities($this->entitiesByRealm($rawBody), $matched);
        app(EnqueueWebhookSync::class)->flush($this->store);

        return response()->json(['ok' => true]);
    }

    /**
     * Realm ids from the raw body, used only to choose connections to test.
     *
     * @return array<int, string>
     */
    private function realmIds(string $rawBody): array
    {
        $decoded = json_decode($rawBody, true);
        if (!is_array($decoded)) {
            return [];
        }
        $notifications = $decoded['eventNotifications'] ?? null;
        if (!is_array($notifications)) {
            return [];
        }

        $ids = [];
        foreach ($notifications as $notification) {
            if (!is_array($notification)) {
                continue;
            }
            $realmId = $this->stringId($notification['realmId'] ?? null);
            if ($realmId === null || in_array($realmId, $ids, true)) {
                continue;
            }
            $ids[] = $realmId;
        }

        return $ids;
    }

    /**
     * Connections on the named realms whose own verifier matches this body.
     * A match for one company does not include the other companies on the realm.
     *
     * @param array<int, string> $realmIds
     *
     * @return array<string, array<int, Connection>>
     */
    private function matchingConnections(string $rawBody, string $signature, array $realmIds): array
    {
        $matched = [];
        foreach ($realmIds as $realmId) {
            foreach ($this->connectionsForRealm($realmId) as $connection) {
                $companyUuid = (string) $connection->company_uuid;
                if ($companyUuid === '') {
                    continue;
                }
                $verifiers = $this->settings->webhookVerifiersFor($this->store, $companyUuid);
                if (!$this->signatures->accepts($rawBody, $signature, $verifiers)) {
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
        if (!is_array($decoded)) {
            return [];
        }
        $notifications = $decoded['eventNotifications'] ?? null;
        if (!is_array($notifications)) {
            return [];
        }

        $groups = [];
        foreach ($notifications as $notification) {
            if (!is_array($notification)) {
                continue;
            }
            $realmId = $this->stringId($notification['realmId'] ?? null);
            if ($realmId === null) {
                continue;
            }
            $change   = $notification['dataChangeEvent'] ?? null;
            $entities = is_array($change) ? ($change['entities'] ?? null) : null;
            if (!is_array($entities)) {
                continue;
            }
            foreach ($entities as $entity) {
                if (!is_array($entity)) {
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
        if (!is_string($name)) {
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
        if (!is_string($operation)) {
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
        if (is_int($value)) {
            $value = (string) $value;
        }
        if (!is_string($value) || $value === '') {
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
     * @param array<string, array<int, Connection>>                                                                                        $connectionsByRealm
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
            foreach ($connections as $connection) {
                $companyUuid = (string) $connection->company_uuid;
                if ($companyUuid === '') {
                    continue;
                }
                $locals = $this->localUuids($companyUuid, $group['realm'], $ids);
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
            if (!$link instanceof Link) {
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
            if ($connection instanceof Connection) {
                $connections[] = $connection;
            }
        }

        return $connections;
    }
}
