<?php

namespace Fleetbase\Quickbooks\Services\Concerns;

use Fleetbase\Quickbooks\Models\Connection;
use Fleetbase\Quickbooks\Models\Link;
use Fleetbase\Quickbooks\Services\SyncEngine;

/**
 * FleetbaseDirectory's link lookups by QuickBooks id: which QuickBooks records another
 * Fleetbase record already claims, and the local records linked to them. Split from StoresLinks
 * to keep each file readable.
 */
trait FindsClaimedRecords
{
    /**
     * QuickBooks entity name to the ids named by this delivery.
     *
     * @param array<int, mixed> $entities
     *
     * @return array<string, array<string, true>>
     */
    private function remoteIdsByEntity(array $entities): array
    {
        $known = [
            'Customer' => true,
            'Invoice'  => true,
            'Payment'  => true,
            'Account'  => true,
        ];
        // A payment re-read is an invoice read, so it loads the invoice link.
        $aliases = [SyncEngine::INVOICE_PAYMENTS => 'Invoice'];
        $ids     = [];
        foreach ($entities as $entity) {
            if (is_array($entity) === false) {
                continue;
            }
            $name = $entity['entity'] ?? null;
            $name = is_string($name) === true ? ($aliases[$name] ?? $name) : $name;
            if (is_string($name) === false || isset($known[$name]) === false) {
                continue;
            }
            $entityId = $entity['id'] ?? null;
            if (is_int($entityId) === true) {
                $entityId = (string) $entityId;
            }
            if (is_string($entityId) === false || $entityId === '') {
                continue;
            }
            $ids[$name][$entityId] = true;
        }

        return $ids;
    }

    /**
     * Which of these QuickBooks ids this organization already links on that realm. With no
     * organization, links from every organization on that realm count. A link of $exceptLocalUuid does not.
     *
     * @param array<int, string> $ids
     *
     * @return array<int, string>
     */
    public function linkedRemoteIds(
        ?string $companyUuid,
        string $realm,
        string $entity,
        array $ids,
        ?string $exceptLocalUuid = null,
    ): array {
        $ids = array_values(array_unique(array_filter(array_map('strval', $ids))));
        if ($ids === [] || $realm === '') {
            return [];
        }
        if ($this->memory !== null) {
            $wanted = array_flip($ids);
            $linked = array_filter($this->memory->links, static fn (array $link): bool => ($companyUuid === null
                    || ($link['company_uuid'] ?? null) === $companyUuid)
                && ($exceptLocalUuid === null || ($link['local_uuid'] ?? null) !== $exceptLocalUuid)
                && (string) ($link['realm_id'] ?? '') === $realm
                && ($link['qbo_entity'] ?? null) === $entity
                && isset($wanted[(string) ($link['qbo_id'] ?? '')]) === true);

            return array_values(array_unique(array_map(
                static fn (mixed $remoteId): string => (string) $remoteId,
                array_column($linked, 'qbo_id')
            )));
        }

        return array_values(array_map(static fn (mixed $remoteId): string => (string) $remoteId, Link::query()
            ->when($companyUuid !== null, static fn ($query) => $query->where('company_uuid', $companyUuid))
            ->when($exceptLocalUuid !== null, static fn ($query) => $query->where('local_uuid', '!=', $exceptLocalUuid))
            ->where('realm_id', $realm)
            ->where('qbo_entity', $entity)
            ->whereIn('qbo_id', $ids)
            ->pluck('qbo_id')
            ->all()));
    }

    /**
     * Which of these QuickBooks ids a Fleetbase record still syncs with, for the guard that keeps
     * one QuickBooks record to one Fleetbase record. A link counts when its organization is
     * $companyUuid or is connected to this realm: an organization that disconnected keeps its
     * links for a reconnect, and they do not hold the record. A customer link of a deleted
     * Fleet-Ops customer does not count either. One query reads the links; a second runs only
     * when customer links were found, since Fleet-Ops may keep its tables on another connection.
     *
     * @param array<int, string> $ids
     *
     * @return array<int, string>
     */
    private function claimedRemoteIds(
        string $companyUuid,
        string $realm,
        string $entity,
        array $ids,
        string $exceptLocalUuid,
    ): array {
        $ids = array_values(array_unique(array_filter(array_map('strval', $ids))));
        if ($ids === [] || $realm === '') {
            return [];
        }
        $connections = (new Connection())->newQuery()->select('company_uuid')->where('realm_id', $realm);
        $links       = Link::query()
            ->where('realm_id', $realm)
            ->where('qbo_entity', $entity)
            ->whereIn('qbo_id', $ids)
            ->where('local_uuid', '!=', $exceptLocalUuid)
            ->where(static function ($owner) use ($companyUuid, $connections): void {
                $owner->where('company_uuid', $companyUuid)->orWhereIn('company_uuid', $connections);
            })
            ->get(['qbo_id', 'local_type', 'local_uuid']);
        $deleted = $this->deletedCustomerUuids($links->where('local_type', 'customer')->pluck('local_uuid')->all());
        $claimed = [];
        foreach ($links as $link) {
            if ($link instanceof Link === false) {
                continue;
            }
            if ($link->local_type !== 'customer' || isset($deleted[(string) $link->local_uuid]) === false) {
                $claimed[(string) $link->qbo_id] = (string) $link->qbo_id;
            }
        }

        return array_values($claimed);
    }

    /**
     * @param array<string, array<string, true>> $idsByEntity
     *
     * @return array<int, array<string, mixed>>
     */
    private function linksForRemoteIds(string $companyUuid, string $realm, array $idsByEntity): array
    {
        if ($idsByEntity === []) {
            return [];
        }

        $query = (new Link())->newQuery()->where('company_uuid', $companyUuid);
        if ($realm !== '') {
            $query->where('realm_id', $realm);
        }
        $query->where(function ($scope) use ($idsByEntity): void {
            $started = false;
            foreach ($idsByEntity as $entity => $ids) {
                $idList = array_keys($ids);
                $method = $started === true ? 'orWhere' : 'where';
                $scope->{$method}(function ($inner) use ($entity, $idList): void {
                    $inner->where('qbo_entity', $entity)->whereIn('qbo_id', $idList);
                });
                $started = true;
            }
        });

        $rows = [];
        foreach ($query->get() as $link) {
            if ($link instanceof Link) {
                $rows[] = $link->toArray();
            }
        }

        return $rows;
    }

    /**
     * @param array<string, array<string, true>> $idsByEntity
     *
     * @return array<int, array<string, mixed>>
     */
    private function memoryLinksForRemoteIds(string $companyUuid, string $realm, array $idsByEntity): array
    {
        if ($this->memory === null || $idsByEntity === []) {
            return [];
        }

        $rows = [];
        foreach ($this->memory->links as $link) {
            if (is_array($link) === false || (string) ($link['company_uuid'] ?? '') !== $companyUuid) {
                continue;
            }
            if ($realm !== '' && (string) ($link['realm_id'] ?? '') !== $realm) {
                continue;
            }
            $entity       = (string) ($link['qbo_entity'] ?? '');
            $remoteId     = (string) ($link['qbo_id'] ?? '');
            if (isset($idsByEntity[$entity][$remoteId]) === false) {
                continue;
            }
            $rows[] = $link;
        }

        return $rows;
    }

    /**
     * @param array<int, array<string, mixed>> $links
     *
     * @return array{0: array<int, string>, 1: array<int, string>, 2: array<int, string>}
     */
    private function localIdsFromLinks(array $links): array
    {
        $customerIds = [];
        $invoiceIds  = [];
        $walletIds   = [];
        foreach ($links as $link) {
            $uuid = (string) ($link['local_uuid'] ?? '');
            if ($uuid === '') {
                continue;
            }
            $type = (string) ($link['local_type'] ?? '');
            if ($type === 'customer') {
                $customerIds[] = $uuid;
            } elseif ($type === 'wallet') {
                $walletIds[] = $uuid;
            } elseif ($type === 'invoice' || $type === 'payment') {
                $invoiceIds[] = $uuid;
            }
        }

        return [$customerIds, $invoiceIds, $walletIds];
    }
}
