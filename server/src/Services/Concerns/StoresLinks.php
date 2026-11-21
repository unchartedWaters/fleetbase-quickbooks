<?php

namespace Fleetbase\Quickbooks\Services\Concerns;

use Fleetbase\Quickbooks\Models\Connection;
use Fleetbase\Quickbooks\Models\Link;
use Fleetbase\Quickbooks\Services\SyncEngine;
use Fleetbase\Quickbooks\Services\SyncLedger;
use Fleetbase\Quickbooks\Support\PaymentTokens;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;

/**
 * FleetbaseDirectory's link storage: reading and writing QuickBooks links, which QuickBooks
 * records are already claimed, and the payment-to-invoice rows. It shares the directory's state,
 * so it lives here only to keep FleetbaseDirectory.php readable.
 */
trait StoresLinks
{
    /**
     * @param array<string, true> $skipped
     */
    private function writeLinks(SyncLedger $ledger, array $skipped): void
    {
        $loadedByUuid          = $this->loadedLinksByUuid();
        $linkFields            = [
            'company_uuid', 'realm_id', 'local_type', 'local_uuid', 'qbo_entity', 'qbo_id', 'sync_token',
            'name', 'state', 'invoice_tokens', 'kept_customer',
            'kept_from', 'tax_source', 'refused_tax',
        ];
        $freshLinks                  = [];
        $changedLinks                = [];
        $identityReleases            = [];
        $this->linkedCustomers       = [];
        $this->linkedRemoteCustomers = [];
        foreach ($ledger->links as $link) {
            if (is_array($link) === false || isset($skipped[(string) ($link['company_uuid'] ?? '')]) === true) {
                continue;
            }
            $key     = (string) ($link['company_uuid'] ?? '') . '|' . (string) ($link['local_type'] ?? '') . '|' . (string) ($link['local_uuid'] ?? '');
            $loaded  = $this->loadedLink($key, $link, $loadedByUuid);
            $changed = self::changedColumns($link, is_array($loaded) === true ? $loaded : null, $linkFields);
            if ($changed === [] && is_array($loaded) === true) {
                continue;
            }
            $relinked = is_array($loaded) === false || isset($changed['qbo_id']) === true;
            if (($link['local_type'] ?? '') === 'customer' && $relinked === true) {
                $this->noteLinkedCustomer($link);
            }
            if (is_array($loaded) === true) {
                $uuid = (string) ($loaded['uuid'] ?? '');
                if (isset($changed['local_uuid']) === true || isset($changed['local_type']) === true) {
                    // The new local key may already be stored. Free it before this row moves,
                    // or the unique local identity rejects the update and insertOrIgnore keeps the old key.
                    $identityReleases[] = ['link' => $link, 'keep' => $uuid];
                }
                if ($uuid === '') {
                    $this->upsertLinkByLocalKey($link);
                    continue;
                }
                $changedLinks[] = ['uuid' => $uuid, 'columns' => $changed, 'loaded' => $loaded];
                continue;
            }
            $freshLinks[] = $link;
        }
        $inserted = $this->insertLinks($freshLinks);
        $this->releaseLinkIdentities(array_merge($identityReleases, $inserted['releases']));
        $this->updateByUuid(new Link(), $this->withMergedInvoiceTokens($changedLinks), $linkFields);
        $this->updateByUuid(new Link(), $inserted['updates'], $linkFields);
        $this->dropStaleInvoicePaymentLinks($ledger, $skipped);
        $this->rememberPaymentInvoices($ledger, $skipped);
    }

    /**
     * A payment link's invoice tokens (see PaymentTokens) are merged into the value stored now,
     * read again under a lock in this save, so a token another run stored after this one
     * loaded the link is kept.
     *
     * @param array<int, array{uuid: string, columns: array<string, mixed>, loaded: array<string, mixed>}> $changedLinks
     *
     * @return array<int, array{uuid: string, columns: array<string, mixed>, loaded: array<string, mixed>}>
     */
    private function withMergedInvoiceTokens(array $changedLinks): array
    {
        $column = PaymentTokens::COLUMN;
        $uuids  = [];
        foreach ($changedLinks as $change) {
            if (array_key_exists($column, $change['columns']) === true) {
                $uuids[] = $change['uuid'];
            }
        }
        $stored = [];
        foreach (array_chunk($uuids, 500) as $chunk) {
            $stored += Link::query()->whereIn('uuid', $chunk)->lockForUpdate()->pluck($column, 'uuid')->all();
        }
        foreach ($changedLinks as $index => $change) {
            $merges = array_key_exists($column, $change['columns']) === true;
            if ($merges === false || array_key_exists($change['uuid'], $stored) === false) {
                continue;
            }
            $changedLinks[$index]['columns'][$column] = PaymentTokens::merge(
                is_string($change['loaded'][$column] ?? null) === true ? $change['loaded'][$column] : null,
                is_string($change['columns'][$column]) === true ? $change['columns'][$column] : null,
                is_string($stored[$change['uuid']]) === true ? $stored[$change['uuid']] : null
            );
        }

        return $changedLinks;
    }

    /**
     * The links loaded for this batch, keyed by their uuid.
     *
     * @return array<string, array<string, mixed>>
     */
    private function loadedLinksByUuid(): array
    {
        $loadedByUuid = [];
        foreach ($this->loaded['links'] as $loadedLink) {
            if (is_array($loadedLink) === false) {
                continue;
            }
            $loadedUuid = (string) ($loadedLink['uuid'] ?? '');
            if ($loadedUuid !== '') {
                $loadedByUuid[$loadedUuid] = $loadedLink;
            }
        }

        return $loadedByUuid;
    }

    /**
     * Saves a link that was loaded without a uuid, matched on its local identity.
     *
     * @param array<string, mixed> $link
     */
    private function upsertLinkByLocalKey(array $link): void
    {
        $payload = $link;
        unset($payload['invoice_uuid']);
        Link::query()->updateOrCreate(
            [
                'company_uuid' => $link['company_uuid'],
                'local_type'   => $link['local_type'],
                'local_uuid'   => $link['local_uuid'],
            ],
            $payload
        );
    }

    /**
     * @param array<int, array{entity?: mixed, id?: mixed, operation?: mixed}> $entities
     */
    private function linkedLedger(string $companyUuid, array $entities): SyncLedger
    {
        $ledger     = new SyncLedger();
        $connection = $this->connection($companyUuid);
        if ($connection === null) {
            return $ledger;
        }

        $ledger->connections[$companyUuid] = $connection;
        $this->loaded['pending']           = [];
        $this->loaded['links']             = [];
        $idsByEntity                       = $this->remoteIdsByEntity($entities);
        $realm                             = (string) ($connection['realm_id'] ?? '');
        $matched                           = $this->memory !== null
            ? $this->memoryLinksForRemoteIds($companyUuid, $realm, $idsByEntity)
            : $this->linksForRemoteIds($companyUuid, $realm, $idsByEntity);
        [$customerIds, $invoiceIds, $walletIds] = $this->localIdsFromLinks($matched);
        $this->attachLinkedRows($ledger, $companyUuid, $customerIds, $invoiceIds, $walletIds);
        $ledger->rebuildIndex();
        $this->loaded['customers'] = $ledger->customers;
        $this->loaded['invoices']  = $ledger->invoices;
        $this->loaded['wallets']   = $ledger->wallets;

        $ledger->customerLinkReader = $this->customerLinkReader($companyUuid);

        return $ledger;
    }

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
            $id = $entity['id'] ?? null;
            if (is_int($id) === true) {
                $id = (string) $id;
            }
            if (is_string($id) === false || $id === '') {
                continue;
            }
            $ids[$name][$id] = true;
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
                static fn (mixed $id): string => (string) $id,
                array_column($linked, 'qbo_id')
            )));
        }

        return array_values(array_map(static fn (mixed $id): string => (string) $id, Link::query()
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
     * Add this organization's stored customer links that a customer import page needs: those
     * to these QuickBooks customers and those of these Fleetbase customers. They count as
     * loaded, so the import's "already linked" checks see them and the save writes only what
     * the import changed. Links the ledger already holds are kept as they are.
     *
     * @param array<int, string> $qboIds
     * @param array<int, string> $localUuids
     */
    public function rememberCustomerLinks(
        SyncLedger $ledger,
        string $companyUuid,
        string $realm,
        array $qboIds,
        array $localUuids,
    ): void {
        if ($this->memory !== null || $companyUuid === '') {
            return;
        }
        $held = [];
        foreach ($ledger->links as $link) {
            $held[$this->customerLinkKey($link)] = true;
        }
        $added = false;
        foreach ($this->storedCustomerLinks($companyUuid, $realm, $qboIds, $localUuids) as $link) {
            $key = $this->customerLinkKey($link);
            if (isset($held[$key]) === true) {
                continue;
            }
            $held[$key] = true;
            $this->rememberLoadedLink($ledger, $link);
            $added = true;
        }
        if ($added === true) {
            $ledger->rebuildIndex();
        }
    }

    /**
     * @param array<string, mixed> $link
     */
    private function customerLinkKey(array $link): string
    {
        return (string) ($link['company_uuid'] ?? '') . '|' . (string) ($link['local_type'] ?? '')
            . '|' . (string) ($link['local_uuid'] ?? '');
    }

    /**
     * @param array<int, string> $qboIds
     * @param array<int, string> $localUuids
     *
     * @return array<int, array<string, mixed>>
     */
    private function storedCustomerLinks(string $companyUuid, string $realm, array $qboIds, array $localUuids): array
    {
        $query = static fn () => Link::query()->where('company_uuid', $companyUuid)->where('local_type', 'customer');
        $reads = [];
        $ids   = array_values(array_unique(array_filter(array_map('strval', $qboIds))));
        if ($realm !== '') {
            foreach (array_chunk($ids, 500) as $chunk) {
                $reads[] = $query()->where('realm_id', $realm)->where('qbo_entity', 'Customer')
                    ->whereIn('qbo_id', $chunk);
            }
        }
        $uuids = array_values(array_unique(array_filter(array_map('strval', $localUuids))));
        foreach (array_chunk($uuids, 500) as $chunk) {
            $reads[] = $query()->whereIn('local_uuid', $chunk);
        }
        $rows = [];
        foreach ($reads as $read) {
            foreach ($read->get() as $link) {
                if ($link instanceof Link) {
                    $rows[] = $link->toArray();
                }
            }
        }

        return $rows;
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
            $entity = (string) ($link['qbo_entity'] ?? '');
            $id     = (string) ($link['qbo_id'] ?? '');
            if (isset($idsByEntity[$entity][$id]) === false) {
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

    /**
     * Invoices for the linked ids, their customers, and the links for those rows.
     * A pending batch loads this same neighborhood once it knows the local ids.
     *
     * @param array<int, string> $customerIds
     * @param array<int, string> $invoiceIds
     * @param array<int, string> $walletIds
     */
    private function attachLinkedRows(SyncLedger $ledger, string $companyUuid, array $customerIds, array $invoiceIds, array $walletIds): void
    {
        $invoiceIds = array_values(array_unique(array_filter($invoiceIds, static fn (string $id): bool => $id !== '')));
        $walletIds  = array_values(array_unique(array_filter($walletIds, static fn (string $id): bool => $id !== '')));
        if ($this->memory !== null) {
            foreach ($invoiceIds as $uuid) {
                $invoice = $this->memory->invoices[$uuid] ?? null;
                if (is_array($invoice) === true) {
                    $ledger->invoices[$uuid] = $invoice;
                }
            }
            $customerIds = $this->withInvoiceCustomers($ledger->invoices, $customerIds);
            foreach ($customerIds as $uuid) {
                $customer = $this->memory->customers[$uuid] ?? null;
                if (is_array($customer) === true) {
                    $ledger->customers[$uuid] = $customer;
                }
            }
            foreach ($walletIds as $uuid) {
                $wallet = $this->memory->wallets[$uuid] ?? null;
                if (is_array($wallet) === true) {
                    $ledger->wallets[$uuid] = $wallet;
                }
            }
            $paymentIds = $this->paymentIdsRememberedFor($this->memory->links, $companyUuid, $invoiceIds);
            foreach ($this->memory->links as $link) {
                if (is_array($link) === false || $this->linkMatchesLocalIds($link, $companyUuid, $customerIds, $invoiceIds, $walletIds, $paymentIds) === false) {
                    continue;
                }
                $this->rememberLoadedLink($ledger, $link);
            }

            return;
        }

        $ledger->invoices  = $this->readInvoices($companyUuid, $invoiceIds, $invoiceIds);
        $customerIds       = $this->withInvoiceCustomers($ledger->invoices, $customerIds);
        $ledger->customers = $this->readCustomers($companyUuid, $customerIds);
        $ledger->wallets   = $this->readWallets($companyUuid, $walletIds);
        $this->readLinksFor($ledger, $companyUuid, $customerIds, $invoiceIds, $walletIds);
    }

    /**
     * @param array<string, array<string, mixed>> $invoices
     * @param array<int, string>                  $customerIds
     *
     * @return array<int, string>
     */
    private function withInvoiceCustomers(array $invoices, array $customerIds): array
    {
        foreach ($invoices as $invoice) {
            $customerId = (string) ($invoice['customer_uuid'] ?? '');
            if ($customerId !== '') {
                $customerIds[] = $customerId;
            }
        }

        return array_values(array_unique(array_filter($customerIds, static fn (string $id): bool => $id !== '')));
    }

    /**
     * @param array<string, mixed> $link
     * @param array<int, string>   $customerIds
     * @param array<int, string>   $invoiceIds
     * @param array<int, string>   $walletIds
     * @param array<int, string>   $paymentIds  QuickBooks payment ids remembered for $invoiceIds
     */
    private function linkMatchesLocalIds(array $link, string $companyUuid, array $customerIds, array $invoiceIds, array $walletIds, array $paymentIds = []): bool
    {
        if ((string) ($link['company_uuid'] ?? '') !== $companyUuid) {
            return false;
        }

        $type = (string) ($link['local_type'] ?? '');
        $uuid = (string) ($link['local_uuid'] ?? '');
        if ($type === 'customer' && in_array($uuid, $customerIds, true) === true) {
            return true;
        }
        if ($type === 'wallet' && in_array($uuid, $walletIds, true) === true) {
            return true;
        }
        if ($type === 'payment-invoice' && in_array(trim((string) ($link['qbo_id'] ?? '')), $invoiceIds, true) === true) {
            return true;
        }
        if ($type === 'payment' && $paymentIds !== [] && in_array($uuid, $paymentIds, true) === true) {
            return true;
        }

        return ($type === 'invoice' || $type === 'payment') && in_array($uuid, $invoiceIds, true) === true;
    }

    /**
     * payment-invoice.local_uuid is the QuickBooks payment id and qbo_id is the Fleetbase invoice.
     *
     * @param array<int, array<string, mixed>> $links
     * @param array<int, string>               $invoiceIds
     *
     * @return array<int, string>
     */
    private function paymentIdsRememberedFor(array $links, string $companyUuid, array $invoiceIds): array
    {
        if ($invoiceIds === [] || $links === []) {
            return [];
        }

        $wanted = array_fill_keys($invoiceIds, true);
        $ids    = [];
        foreach ($links as $link) {
            if (is_array($link) === false || (string) ($link['company_uuid'] ?? '') !== $companyUuid) {
                continue;
            }
            if ((string) ($link['local_type'] ?? '') !== 'payment-invoice') {
                continue;
            }
            $invoiceUuid = trim((string) ($link['qbo_id'] ?? ''));
            $paymentId   = trim((string) ($link['local_uuid'] ?? ''));
            if ($paymentId === '' || $invoiceUuid === '' || isset($wanted[$invoiceUuid]) === false) {
                continue;
            }
            $ids[] = $paymentId;
        }

        return array_values(array_unique($ids));
    }

    /**
     * @param array<string, mixed> $link
     */
    private function rememberLoadedLink(SyncLedger $ledger, array $link): void
    {
        $ledger->links[]             = $link;
        $key                         = (string) ($link['company_uuid'] ?? '') . '|' . (string) ($link['local_type'] ?? '') . '|' . (string) ($link['local_uuid'] ?? '');
        $this->loaded['links'][$key] = $link;
    }

    /**
     * A rekeyed payment keeps its link uuid and changes local_uuid from the invoice
     * to the QuickBooks payment id. insertOrIgnore of that same primary key would
     * leave the invoice-uuid row in place.
     *
     * @param array<string, mixed>                $link
     * @param array<string, array<string, mixed>> $loadedByUuid
     *
     * @return array<string, mixed>|null
     */
    private function loadedLink(string $key, array $link, array $loadedByUuid): ?array
    {
        $loaded = $this->loaded['links'][$key] ?? null;
        if (is_array($loaded) === true) {
            return $loaded;
        }
        $uuid = (string) ($link['uuid'] ?? '');
        if ($uuid === '' || isset($loadedByUuid[$uuid]) === false || is_array($loadedByUuid[$uuid]) === false) {
            return null;
        }

        return $loadedByUuid[$uuid];
    }

    /**
     * Delete every other row that already occupies one of these local identities.
     * One statement for the chunk, instead of a delete per payment.
     *
     * @param array<int, array{link: array<string, mixed>, keep: string}> $releases
     */
    private function releaseLinkIdentities(array $releases): void
    {
        $groups = [];
        foreach ($releases as $release) {
            $link    = $release['link'];
            $company = (string) ($link['company_uuid'] ?? '');
            $type    = (string) ($link['local_type'] ?? '');
            $local   = (string) ($link['local_uuid'] ?? '');
            if ($company === '' || $type === '' || $local === '') {
                continue;
            }
            $groups[] = [
                'company' => $company,
                'type'    => $type,
                'local'   => $local,
                'keep'    => (string) ($release['keep'] ?? ''),
            ];
        }
        if ($groups === []) {
            return;
        }

        foreach (array_chunk($groups, 200) as $chunk) {
            Link::query()->where(function ($query) use ($chunk): void {
                foreach ($chunk as $index => $group) {
                    $method = $index === 0 ? 'where' : 'orWhere';
                    $query->{$method}(function ($inner) use ($group): void {
                        $inner->where('company_uuid', $group['company'])
                            ->where('local_type', $group['type'])
                            ->where('local_uuid', $group['local']);
                        if ($group['keep'] !== '') {
                            $inner->where('uuid', '!=', $group['keep']);
                        }
                    });
                }
            })->delete();
        }
    }

    /**
     * One QuickBooks payment id is stored under that id. An older row that still
     * uses the invoice uuid for the same payment is removed once the new key is stored.
     *
     * @param array<string, true> $skipped
     */
    private function dropStaleInvoicePaymentLinks(SyncLedger $ledger, array $skipped): void
    {
        $groups = [];
        foreach ($ledger->links as $link) {
            if (is_array($link) === false || isset($skipped[(string) ($link['company_uuid'] ?? '')]) === true) {
                continue;
            }
            if ((string) ($link['local_type'] ?? '') !== 'payment') {
                continue;
            }
            $local   = (string) ($link['local_uuid'] ?? '');
            $qboId   = (string) ($link['qbo_id'] ?? '');
            $company = (string) ($link['company_uuid'] ?? '');
            $realm   = (string) ($link['realm_id'] ?? '');
            if ($local === '' || $qboId === '' || $local !== $qboId || $company === '' || $realm === '') {
                continue;
            }
            $groups[$company . '|' . $realm . '|' . $qboId] = [
                'company' => $company,
                'realm'   => $realm,
                'qbo_id'  => $qboId,
                'local'   => $local,
            ];
        }
        if ($groups === []) {
            return;
        }

        // The kept payment-id rows were inserted or updated above. One delete removes
        // every invoice-uuid row for those payments.
        foreach (array_chunk(array_values($groups), 200) as $chunk) {
            Link::query()->where(function ($query) use ($chunk): void {
                foreach ($chunk as $index => $group) {
                    $method = $index === 0 ? 'where' : 'orWhere';
                    $query->{$method}(function ($inner) use ($group): void {
                        $inner->where('company_uuid', $group['company'])
                            ->where('realm_id', $group['realm'])
                            ->where('local_type', 'payment')
                            ->where('qbo_id', $group['qbo_id'])
                            ->where('local_uuid', '!=', $group['local']);
                    });
                }
            })->delete();
        }
    }

    /**
     * A payment stored under the QuickBooks payment id remembers the Fleetbase invoice.
     * A rekey still has that invoice on the older link. A first save whose line already
     * names the invoice carries invoice_uuid, because a later delete cannot read the payment.
     *
     * @param array<string, true> $skipped
     */
    private function rememberPaymentInvoices(SyncLedger $ledger, array $skipped): void
    {
        $rows = $this->paymentInvoiceRows($ledger, $skipped, $this->invoiceUuidByPaymentLink($skipped));
        if ($rows === []) {
            return;
        }
        $this->storePaymentInvoiceRows($rows);
    }

    /**
     * @param array<string, true> $skipped
     *
     * @return array<string, string>
     */
    private function invoiceUuidByPaymentLink(array $skipped): array
    {
        $invoiceByPayment = [];
        foreach ($this->loaded['links'] as $loaded) {
            $parts = $this->usablePaymentLink($loaded, $skipped, false);
            if ($parts === null) {
                continue;
            }
            [$local, $qboId, $company, $realm]                        = $parts;
            $invoiceByPayment[$company . '|' . $realm . '|' . $qboId] = $local;
        }

        return $invoiceByPayment;
    }

    /**
     * @param array<string, mixed>|mixed $link
     * @param array<string, true>        $skipped
     *
     * @return array{0: string, 1: string, 2: string, 3: string}|null
     */
    private function usablePaymentLink(mixed $link, array $skipped, bool $localMatchesRemote): ?array
    {
        if (is_array($link) === false || (string) ($link['local_type'] ?? '') !== 'payment') {
            return null;
        }
        $local   = (string) ($link['local_uuid'] ?? '');
        $qboId   = (string) ($link['qbo_id'] ?? '');
        $company = (string) ($link['company_uuid'] ?? '');
        $realm   = (string) ($link['realm_id'] ?? '');
        $same    = $local === $qboId;
        if ($local === '' || $qboId === '' || $same !== $localMatchesRemote || $company === '' || $realm === '' || isset($skipped[$company]) === true) {
            return null;
        }

        return [$local, $qboId, $company, $realm];
    }

    /**
     * @param array<string, true>   $skipped
     * @param array<string, string> $invoiceByPayment
     *
     * @return array<string, array{company_uuid: string, realm_id: string, local_uuid: string, qbo_id: string}>
     */
    private function paymentInvoiceRows(SyncLedger $ledger, array $skipped, array $invoiceByPayment): array
    {
        $rows = [];
        foreach ($ledger->links as $link) {
            $parts = $this->usablePaymentLink($link, $skipped, true);
            if ($parts === null) {
                continue;
            }
            [, $qboId, $company, $realm] = $parts;
            $invoice                     = trim((string) ($link['invoice_uuid'] ?? ''));
            if ($invoice === '' || $invoice === $qboId) {
                $invoice = $invoiceByPayment[$company . '|' . $realm . '|' . $qboId] ?? '';
            }
            if ($invoice === '' || $invoice === $qboId) {
                continue;
            }
            $rows[$company . '|' . $realm . '|' . $qboId] = [
                'company_uuid' => $company,
                'realm_id'     => $realm,
                'local_uuid'   => $qboId,
                'qbo_id'       => $invoice,
            ];
        }

        return $rows;
    }

    /**
     * @param array<string, array{company_uuid: string, realm_id: string, local_uuid: string, qbo_id: string}> $rows
     */
    private function storePaymentInvoiceRows(array $rows): void
    {
        $now       = Carbon::now()->toDateTimeString();
        $existing  = [];
        $byCompany = [];
        foreach ($rows as $row) {
            $byCompany[$row['company_uuid']][] = $row['local_uuid'];
        }
        // The company filter lets the (company_uuid, local_type, local_uuid) unique index serve the lookup.
        foreach ($byCompany as $company => $localUuids) {
            foreach (array_chunk($localUuids, 200) as $chunk) {
                foreach (Link::query()->where('company_uuid', (string) $company)->where('local_type', 'payment-invoice')->whereIn('local_uuid', $chunk)->get(['uuid', 'company_uuid', 'realm_id', 'local_uuid', 'qbo_id']) as $link) {
                    if (is_object($link) === false) {
                        continue;
                    }
                    $existing[(string) $link->company_uuid . '|' . (string) $link->realm_id . '|' . (string) $link->local_uuid] = [
                        'uuid'   => (string) $link->uuid,
                        'qbo_id' => (string) $link->qbo_id,
                    ];
                }
            }
        }
        $inserts = [];
        $updates = [];
        foreach ($rows as $key => $row) {
            $stored = $existing[$key] ?? null;
            if (is_array($stored) === true) {
                if ($stored['qbo_id'] !== $row['qbo_id']) {
                    $updates[] = ['uuid' => $stored['uuid'], 'columns' => ['qbo_id' => $row['qbo_id']]];
                }
                continue;
            }
            $inserts[] = [
                'uuid'         => (string) Str::uuid(),
                'company_uuid' => $row['company_uuid'],
                'realm_id'     => $row['realm_id'],
                'local_type'   => 'payment-invoice',
                'local_uuid'   => $row['local_uuid'],
                'qbo_entity'   => 'PaymentInvoice',
                'qbo_id'       => $row['qbo_id'],
                'sync_token'   => '0',
                'created_at'   => $now,
                'updated_at'   => $now,
            ];
        }
        foreach (array_chunk($inserts, 200) as $chunk) {
            Link::query()->insert($chunk);
        }
        $this->updateByUuid(new Link(), $updates, ['qbo_id']);
    }

    /**
     * Insert new link rows. Updates of an existing uuid are returned so the
     * identity delete for the whole chunk can run before those updates.
     *
     * @param array<int, array<string, mixed>> $links
     *
     * @return array{releases: array<int, array{link: array<string, mixed>, keep: string}>, updates: array<int, array{uuid: string, columns: array<string, mixed>}>}
     */
    private function insertLinks(array $links): array
    {
        if ($links === []) {
            return ['releases' => [], 'updates' => []];
        }

        $rows     = $this->linkRows($links);
        $existing = $this->existingLinkUuids($rows);

        return $this->partitionLinkRows($rows, $existing);
    }

    /**
     * @param array<int, array<string, mixed>> $links
     *
     * @return array<int, array<string, mixed>>
     */
    private function linkRows(array $links): array
    {
        $now  = Carbon::now()->toDateTimeString();
        $rows = [];
        foreach ($links as $link) {
            $rows[] = [
                'uuid'           => (string) (($link['uuid'] ?? '') !== '' ? $link['uuid'] : Str::uuid()),
                'company_uuid'   => $link['company_uuid'] ?? null,
                'realm_id'       => (string) ($link['realm_id'] ?? ''),
                'local_type'     => (string) ($link['local_type'] ?? ''),
                'local_uuid'     => (string) ($link['local_uuid'] ?? ''),
                'qbo_entity'     => (string) ($link['qbo_entity'] ?? ''),
                'qbo_id'         => (string) ($link['qbo_id'] ?? ''),
                'sync_token'     => (string) ($link['sync_token'] ?? '0'),
                'name'           => $link['name'] ?? null,
                'state'          => $link['state'] ?? null,
                'invoice_tokens' => $link['invoice_tokens'] ?? null,
                'kept_customer'  => $link['kept_customer'] ?? null,
                'kept_from'      => $link['kept_from'] ?? null,
                'tax_source'     => $link['tax_source'] ?? null,
                'refused_tax'    => $link['refused_tax'] ?? null,
                'created_at'     => $now,
                'updated_at'     => $now,
            ];
        }

        return $rows;
    }

    /**
     * @param array<int, array<string, mixed>> $rows
     *
     * @return array<string, true>
     */
    private function existingLinkUuids(array $rows): array
    {
        $existing = [];
        foreach (array_chunk(array_column($rows, 'uuid'), 200) as $chunk) {
            foreach (Link::query()->whereIn('uuid', $chunk)->pluck('uuid') as $uuid) {
                $existing[(string) $uuid] = true;
            }
        }

        return $existing;
    }

    /**
     * @param array<int, array<string, mixed>> $rows
     * @param array<string, true>              $existing
     *
     * @return array{releases: array<int, array{link: array<string, mixed>, keep: string}>, updates: array<int, array{uuid: string, columns: array<string, mixed>}>}
     */
    private function partitionLinkRows(array $rows, array $existing): array
    {
        $inserts  = [];
        $updates  = [];
        $releases = [];
        foreach ($rows as $row) {
            if (isset($existing[$row['uuid']]) === false) {
                $inserts[] = $row;
                continue;
            }
            $releases[] = ['link' => $row, 'keep' => (string) $row['uuid']];
            $updates[]  = [
                'uuid'    => (string) $row['uuid'],
                'columns' => [
                    'company_uuid'   => $row['company_uuid'],
                    'realm_id'       => $row['realm_id'],
                    'local_type'     => $row['local_type'],
                    'local_uuid'     => $row['local_uuid'],
                    'qbo_entity'     => $row['qbo_entity'],
                    'qbo_id'         => $row['qbo_id'],
                    'sync_token'     => $row['sync_token'],
                    'name'           => $row['name'],
                    'state'          => $row['state'],
                    'invoice_tokens' => $row['invoice_tokens'],
                    'kept_customer'  => $row['kept_customer'],
                    'kept_from'      => $row['kept_from'],
                    'tax_source'     => $row['tax_source'],
                    'refused_tax'    => $row['refused_tax'],
                ],
            ];
        }
        foreach (array_chunk($inserts, 200) as $chunk) {
            Link::query()->insertOrIgnore($chunk);
        }

        return ['releases' => $releases, 'updates' => $updates];
    }
}
