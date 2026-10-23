<?php

namespace Fleetbase\Quickbooks\Tests\Support;

/**
 * Webhook test bodies. A change with no timestamp has its deletes and merges withheld, so a body
 * that expects them to be applied carries the current time on every change.
 */
final class WebhookBodies
{
    /**
     * Adds the current time to every change that has none: lastUpdated in the legacy format,
     * time in CloudEvents.
     */
    public static function dated(string $body): string
    {
        $decoded = json_decode($body, true);
        if (is_array($decoded) === false) {
            return $body;
        }
        $now = gmdate('Y-m-d\TH:i:s\Z');
        if (array_key_exists('eventNotifications', $decoded) === true) {
            foreach ($decoded['eventNotifications'] as $index => $notification) {
                foreach ($notification['dataChangeEvent']['entities'] ?? [] as $position => $entity) {
                    $decoded['eventNotifications'][$index]['dataChangeEvent']['entities'][$position]['lastUpdated']
                        = $entity['lastUpdated'] ?? $now;
                }
            }

            return (string) json_encode($decoded, JSON_UNESCAPED_SLASHES);
        }
        foreach ($decoded as $index => $event) {
            $decoded[$index]['time'] = $event['time'] ?? $now;
        }

        return (string) json_encode($decoded, JSON_UNESCAPED_SLASHES);
    }
}
