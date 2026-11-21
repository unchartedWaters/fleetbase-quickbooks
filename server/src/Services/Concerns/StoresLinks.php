<?php

namespace Fleetbase\Quickbooks\Services\Concerns;

use Fleetbase\Quickbooks\Models\Link;
use Fleetbase\Quickbooks\Services\FleetbaseDirectory;
use Fleetbase\Quickbooks\Services\SyncLedger;
use Fleetbase\Quickbooks\Support\PaymentTokens;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;

/**
 * FleetbaseDirectory's link storage: reading and writing QuickBooks links, which QuickBooks
 * records are already claimed, and the payment-to-invoice rows. It shares the directory's state,
 * so it lives here only to keep FleetbaseDirectory.php readable.
 *
 * @phpstan-import-type LinkWrites from FleetbaseDirectory
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
            $key     = self::linkKey($link);
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
     * Invoices for the linked ids, their customers, and the links for those rows.
     * A pending batch loads this same neighborhood once it knows the local ids.
     *
     * @param array<int, string> $customerIds
     * @param array<int, string> $invoiceIds
     * @param array<int, string> $walletIds
     */
    private function attachLinkedRows(
        SyncLedger $ledger,
        string $companyUuid,
        array $customerIds,
        array $invoiceIds,
        array $walletIds,
    ): void {
        $invoiceIds = array_values(array_unique(array_filter(
            $invoiceIds,
            static fn (string $invoiceId): bool => $invoiceId !== ''
        )));
        $walletIds  = array_values(array_unique(array_filter(
            $walletIds,
            static fn (string $walletId): bool => $walletId !== ''
        )));
        if ($this->memory !== null) {
            $this->attachMemoryRows($this->memory, $ledger, $companyUuid, $customerIds, $invoiceIds, $walletIds);

            return;
        }

        $ledger->invoices  = $this->readInvoices($companyUuid, $invoiceIds, $invoiceIds);
        $customerIds       = $this->withInvoiceCustomers($ledger->invoices, $customerIds);
        $ledger->customers = $this->readCustomers($companyUuid, $customerIds);
        $ledger->wallets   = $this->readWallets($companyUuid, $walletIds);
        $this->readLinksFor($ledger, $companyUuid, $customerIds, $invoiceIds, $walletIds);
    }

    /**
     * attachLinkedRows when the directory keeps its rows in memory.
     *
     * @param array<int, string> $customerIds
     * @param array<int, string> $invoiceIds
     * @param array<int, string> $walletIds
     */
    private function attachMemoryRows(
        SyncLedger $memory,
        SyncLedger $ledger,
        string $companyUuid,
        array $customerIds,
        array $invoiceIds,
        array $walletIds,
    ): void {
        foreach ($invoiceIds as $uuid) {
            $invoice = $memory->invoices[$uuid] ?? null;
            if (is_array($invoice) === true) {
                $ledger->invoices[$uuid] = $invoice;
            }
        }
        $customerIds = $this->withInvoiceCustomers($ledger->invoices, $customerIds);
        foreach ($customerIds as $uuid) {
            $customer = $memory->customers[$uuid] ?? null;
            if (is_array($customer) === true) {
                $ledger->customers[$uuid] = $customer;
            }
        }
        foreach ($walletIds as $uuid) {
            $wallet = $memory->wallets[$uuid] ?? null;
            if (is_array($wallet) === true) {
                $ledger->wallets[$uuid] = $wallet;
            }
        }
        $paymentIds = $this->paymentIdsRememberedFor($memory->links, $companyUuid, $invoiceIds);
        foreach ($memory->links as $link) {
            if (is_array($link) === false) {
                continue;
            }
            $matches = $this->linkMatchesLocalIds(
                $link,
                $companyUuid,
                $customerIds,
                $invoiceIds,
                $walletIds,
                $paymentIds
            );
            if ($matches === false) {
                continue;
            }
            $this->rememberLoadedLink($ledger, $link);
        }
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

        return array_values(array_unique(array_filter(
            $customerIds,
            static fn (string $customerId): bool => $customerId !== ''
        )));
    }

    /**
     * @param array<string, mixed> $link
     * @param array<int, string>   $customerIds
     * @param array<int, string>   $invoiceIds
     * @param array<int, string>   $walletIds
     * @param array<int, string>   $paymentIds  QuickBooks payment ids remembered for $invoiceIds
     */
    private function linkMatchesLocalIds(
        array $link,
        string $companyUuid,
        array $customerIds,
        array $invoiceIds,
        array $walletIds,
        array $paymentIds = [],
    ): bool {
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
        if (
            $type === 'payment-invoice'
            && in_array(trim((string) ($link['qbo_id'] ?? '')), $invoiceIds, true) === true
        ) {
            return true;
        }
        if ($type === 'payment' && $paymentIds !== [] && in_array($uuid, $paymentIds, true) === true) {
            return true;
        }

        return ($type === 'invoice' || $type === 'payment') && in_array($uuid, $invoiceIds, true) === true;
    }

    /**
     * @param array<string, mixed> $link
     */
    private static function linkKey(array $link): string
    {
        return (string) ($link['company_uuid'] ?? '') . '|' . (string) ($link['local_type'] ?? '')
            . '|' . (string) ($link['local_uuid'] ?? '');
    }

    /**
     * @param array<string, mixed> $link
     */
    private function rememberLoadedLink(SyncLedger $ledger, array $link): void
    {
        $ledger->links[]             = $link;
        $key                         = self::linkKey($link);
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
     * Insert new link rows. Updates of an existing uuid are returned so the
     * identity delete for the whole chunk can run before those updates.
     *
     * @param array<int, array<string, mixed>> $links
     *
     * @return LinkWrites
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
     * @return LinkWrites
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
