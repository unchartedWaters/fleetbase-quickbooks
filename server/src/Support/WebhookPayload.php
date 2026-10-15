<?php

namespace Fleetbase\Quickbooks\Support;

/**
 * Reads the entity changes in a QuickBooks webhook body. Intuit sends a top-level array of
 * CloudEvents ({type: "qbo.customer.created.v1", intuitentityid, intuitaccountid, time}).
 * The older {eventNotifications: [{realmId, dataChangeEvent: {entities}}]} body is still read.
 */
class WebhookPayload
{
    /**
     * CloudEvents event names and the operation each one stands for.
     *
     * @var array<string, string>
     */
    private const EVENT_OPERATIONS = [
        'created' => 'Create',
        'updated' => 'Update',
        'deleted' => 'Delete',
        'voided'  => 'Void',
        'merged'  => 'Merge',
    ];

    /**
     * Every realm the body names, as Intuit sent it, including realms with no usable change.
     *
     * @return array<int, mixed>
     */
    public static function realms(string $rawBody): array
    {
        $decoded = json_decode($rawBody, true);
        if (is_array($decoded) === false) {
            return [];
        }
        if (array_key_exists('eventNotifications', $decoded) === true) {
            $items = is_array($decoded['eventNotifications']) === true ? $decoded['eventNotifications'] : [];
            $key   = 'realmId';
        } else {
            $items = array_is_list($decoded) === true ? $decoded : [];
            $key   = 'intuitaccountid';
        }

        $realms = [];
        foreach ($items as $item) {
            if (is_array($item) === true) {
                $realms[] = $item[$key] ?? null;
            }
        }

        return $realms;
    }

    /**
     * One entry per entity change, in delivery order. Each entry has the realm, the entity
     * name, id and operation as Intuit sent them, and `time` when the change carried one.
     *
     * @return array<int, array{realm: mixed, name: mixed, id: mixed, operation: mixed, time?: mixed}>
     */
    public static function changes(string $rawBody): array
    {
        $decoded = json_decode($rawBody, true);
        if (is_array($decoded) === false) {
            return [];
        }
        if (array_key_exists('eventNotifications', $decoded) === true) {
            return self::legacyChanges($decoded['eventNotifications']);
        }
        if (array_is_list($decoded) === false) {
            return [];
        }

        $changes = [];
        foreach ($decoded as $event) {
            if (is_array($event) === false || is_string($event['type'] ?? null) === false) {
                continue;
            }
            // namespace.entitytype.eventname.version
            $parts = explode('.', strtolower($event['type']));
            if (count($parts) < 3 || $parts[0] !== 'qbo') {
                continue;
            }
            $change = [
                'realm'     => $event['intuitaccountid'] ?? null,
                'name'      => $parts[1],
                'id'        => $event['intuitentityid'] ?? null,
                'operation' => self::EVENT_OPERATIONS[$parts[2]] ?? $parts[2],
            ];
            if (array_key_exists('time', $event) === true) {
                $change['time'] = $event['time'];
            }
            $changes[] = $change;
        }

        return $changes;
    }

    /**
     * @return array<int, array{realm: mixed, name: mixed, id: mixed, operation: mixed, time?: mixed}>
     */
    private static function legacyChanges(mixed $notifications): array
    {
        if (is_array($notifications) === false) {
            return [];
        }

        $changes = [];
        foreach ($notifications as $notification) {
            if (is_array($notification) === false) {
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
                $found = [
                    'realm'     => $notification['realmId'] ?? null,
                    'name'      => $entity['name'] ?? null,
                    'id'        => $entity['id'] ?? null,
                    'operation' => $entity['operation'] ?? null,
                ];
                if (array_key_exists('lastUpdated', $entity) === true) {
                    $found['time'] = $entity['lastUpdated'];
                }
                $changes[] = $found;
            }
        }

        return $changes;
    }
}
