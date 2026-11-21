<?php

namespace Fleetbase\Quickbooks\Services\Concerns;

use Fleetbase\Quickbooks\Models\Link;
use Fleetbase\Quickbooks\Models\PendingSync;
use Fleetbase\Quickbooks\Services\FleetbaseDirectory;
use Fleetbase\Quickbooks\Support\SafeLog;
use Fleetbase\Quickbooks\Support\Translation;
use Illuminate\Support\Carbon;

/**
 * FleetbaseDirectory's batched QuickBooks deletes: collect the records a batch of deletes
 * touches, then retire them, drop their links and set invoice statuses in a few queries. Split
 * from AppliesRemoteDeletes to keep each file readable.
 *
 * @phpstan-import-type RemoteDelete from FleetbaseDirectory
 */
trait RetiresDeleteBatches
{
    /**
     * @param array<int, RemoteDelete> $deletions
     */
    private function retireDeleteBatch(string $companyUuid, array $deletions): void
    {
        $state = [
            'void_invoices'   => [],
            'sent_invoices'   => [],
            'customers'       => [],
            'wallets'         => [],
            'link_uuids'      => [],
            'wallet_links'    => [],
            'void_realms'     => [],
            'named_payments'  => [],
            'wallet_accounts' => [],
        ];
        $this->collectDeletionRecords($deletions, $state);
        $this->collectDeletionLinks($this->linksForDeletions($companyUuid, $deletions), $deletions, $state);
        $this->applyDeletionBatch($companyUuid, $state);
    }

    /**
     * @param array<int, array<string, mixed>> $deletions
     * @param array<string, mixed>             $state
     */
    private function collectDeletionRecords(array $deletions, array &$state): void
    {
        foreach ($deletions as $deletion) {
            if (is_array($deletion) === false) {
                continue;
            }
            $type  = (string) ($deletion['local_type'] ?? '');
            $realm = (string) ($deletion['realm_id'] ?? '');
            $qbo   = (string) ($deletion['qbo_id'] ?? '');
            $local = (string) ($deletion['local_uuid'] ?? '');
            if ($type === 'invoice' && $local !== '') {
                $state['void_invoices'][]             = $local;
                $state['void_realms'][$realm][$local] = true;
            }
            if ($type === 'customer' && $local !== '') {
                $state['customers'][] = $local;
            }
            if ($type === 'wallet' && $local !== '') {
                $state['wallets'][] = $local;
                $state['wallet_accounts'][$local] ??= $qbo;
            }
            if ($type === 'payment') {
                $this->collectPaymentDeletion($deletion, $realm, $qbo, $local, $state);
            }
        }
    }

    /**
     * @param array<string, mixed> $deletion
     * @param array<string, mixed> $state
     */
    private function collectPaymentDeletion(
        array $deletion,
        string $realm,
        string $qbo,
        string $local,
        array &$state,
    ): void {
        if (empty($deletion['invoices_from_payment']) === false && $qbo !== '') {
            // The payment was read. Its lines name every invoice to unmark.
            // A stored fallback for a different invoice must not be applied.
            $state['named_payments'][$realm . '|' . $qbo] = true;
        }
        if ($local !== '' && $local !== $qbo) {
            $state['sent_invoices'][] = $local;

            return;
        }
        $named = is_array($deletion['invoice_uuids'] ?? null) === true ? $deletion['invoice_uuids'] : [];
        foreach ($named as $invoiceUuid) {
            $invoiceUuid = (string) $invoiceUuid;
            if ($invoiceUuid !== '' && $invoiceUuid !== $qbo) {
                $state['sent_invoices'][] = $invoiceUuid;
            }
        }
    }

    /**
     * @param array<int, array<string, mixed>> $links
     * @param array<int, RemoteDelete>         $deletions
     * @param array<string, mixed>             $state
     */
    private function collectDeletionLinks(array $links, array $deletions, array &$state): void
    {
        foreach ($links as $link) {
            $type  = (string) ($link['local_type'] ?? '');
            $local = (string) ($link['local_uuid'] ?? '');
            $qbo   = (string) ($link['qbo_id'] ?? '');
            $realm = (string) ($link['realm_id'] ?? '');
            $uuid  = (string) ($link['uuid'] ?? '');
            if ($type === 'payment-invoice') {
                $this->collectPaymentInvoiceLink($uuid, $realm, $local, $qbo, $state);
                continue;
            }
            if ($this->deletionMatchesLink($deletions, $type, $realm, $qbo, $local) === false) {
                continue;
            }
            $this->collectMatchedDeletionLink($type, $realm, $qbo, $local, $uuid, $state);
        }
    }

    /**
     * @param array<string, mixed> $state
     */
    private function collectPaymentInvoiceLink(
        string $uuid,
        string $realm,
        string $local,
        string $qbo,
        array &$state,
    ): void {
        if ($uuid !== '') {
            $state['link_uuids'][] = $uuid;
        }
        if (isset($state['named_payments'][$realm . '|' . $local]) === false && $qbo !== '' && $qbo !== $local) {
            $state['sent_invoices'][] = $qbo;
        }
    }

    /**
     * @param array<string, mixed> $state
     */
    private function collectMatchedDeletionLink(
        string $type,
        string $realm,
        string $qbo,
        string $local,
        string $uuid,
        array &$state,
    ): void {
        if ($uuid !== '' && $type === 'wallet' && $local !== '') {
            // Dropped below only when the wallet is really closed.
            $state['wallet_links'][$local][]  = $uuid;
            $state['wallet_accounts'][$local] = $qbo;
        } elseif ($uuid !== '') {
            $state['link_uuids'][] = $uuid;
        }
        if ($local === '') {
            return;
        }
        $this->recordDeletionTarget($type, $realm, $qbo, $local, $state);
    }

    /**
     * @param array<string, mixed> $state
     */
    private function recordDeletionTarget(string $type, string $realm, string $qbo, string $local, array &$state): void
    {
        if ($type === 'invoice') {
            $state['void_invoices'][]             = $local;
            $state['void_realms'][$realm][$local] = true;

            return;
        }
        if ($type === 'payment' && $local !== $qbo) {
            $state['sent_invoices'][] = $local;

            return;
        }
        if ($type === 'customer') {
            $state['customers'][] = $local;

            return;
        }
        if ($type === 'wallet') {
            $state['wallets'][] = $local;
        }
    }

    /**
     * @param array{
     *     void_invoices: array<int, string>,
     *     sent_invoices: array<int, string>,
     *     customers: array<int, string>,
     *     wallets: array<int, string>,
     *     link_uuids: array<int, string>,
     *     wallet_links: array<string, array<int, string>>,
     *     void_realms: array<string, array<string, true>>,
     *     named_payments: array<string, true>,
     *     wallet_accounts: array<string, string>
     * } $state
     */
    private function applyDeletionBatch(string $companyUuid, array $state): void
    {
        $voidInvoices = array_values(array_unique($state['void_invoices']));
        $sentInvoices = array_values(array_unique(array_filter(
            $state['sent_invoices'],
            static fn (string $uuid): bool => $uuid !== '' && in_array($uuid, $voidInvoices, true) === false
        )));
        $customers = array_values(array_unique($state['customers']));
        // Every deleted wallet's pending row is finished, held or not: pushing a held wallet
        // would reactivate the account that was deleted in QuickBooks.
        $settled   = array_values(array_unique($state['wallets']));
        $held      = $this->walletsHoldingBalance($companyUuid, $settled);
        $wallets   = array_values(array_diff($settled, $held));
        $linkUuids = $state['link_uuids'];
        foreach ($wallets as $wallet) {
            foreach ($state['wallet_links'][$wallet] ?? [] as $walletLink) {
                $linkUuids[] = $walletLink;
            }
        }
        $linkUuids = array_values(array_unique($linkUuids));

        $this->updateInvoiceStatuses($companyUuid, $voidInvoices, $sentInvoices);
        foreach ($sentInvoices as $invoiceUuid) {
            $this->paymentRemoved[$companyUuid][$invoiceUuid] = true;
        }
        $this->retireMany('Fleetbase\\FleetOps\\Models\\Customer', $companyUuid, $customers, []);
        $this->retireMany('Fleetbase\\Ledger\\Models\\Wallet', $companyUuid, $wallets, ['status' => 'closed']);
        $this->deleteRetiredLinks($companyUuid, $linkUuids, $state['void_realms']);
        $this->finishPendingMany($companyUuid, [
            'invoice'  => array_values(array_unique(array_merge($voidInvoices, $sentInvoices))),
            'customer' => $customers,
            'wallet'   => $settled,
        ]);
        $notes = [];
        foreach ($held as $wallet) {
            $account = (string) ($state['wallet_accounts'][$wallet] ?? '');
            $notes[] = [
                'local_type' => 'wallet',
                'local_uuid' => $wallet,
                'error'      => Translation::encode(
                    'quickbooks.messages.remote-delete-wallet-balance',
                    ['id' => $account]
                ),
            ];
        }
        $this->noteRemoteDeletes($companyUuid, $notes);
    }

    /**
     * @param array<int, string> $voidUuids
     * @param array<int, string> $sentUuids
     */
    private function updateInvoiceStatuses(string $companyUuid, array $voidUuids, array $sentUuids): void
    {
        if (($voidUuids === [] && $sentUuids === []) || $companyUuid === '') {
            return;
        }
        $class = 'Fleetbase\\Ledger\\Models\\Invoice';
        if (class_exists($class) === false) {
            return;
        }

        try {
            $model      = new $class();
            $connection = $model->getConnection();
        } catch (\Throwable $exception) {
            if ($this->isUnavailableStorage($exception) === true) {
                return;
            }

            throw $exception;
        }
        $grammar                      = $connection->getQueryGrammar();
        [$cases, $filters, $bindings] = $this->invoiceStatusCases($grammar, $voidUuids, $sentUuids);
        $this->runInvoiceStatusUpdate(
            $connection,
            $grammar,
            $model->getTable(),
            $companyUuid,
            $voidUuids,
            $sentUuids,
            $cases,
            $filters,
            $bindings
        );
    }

    /**
     * @param array<int, string> $voidUuids
     * @param array<int, string> $sentUuids
     *
     * @return array{0: array<int, string>, 1: array<int, string>, 2: array<int, mixed>}
     */
    private function invoiceStatusCases(
        \Illuminate\Database\Grammar $grammar,
        array $voidUuids,
        array $sentUuids,
    ): array {
        $uuidColumn = $grammar->wrap('uuid');
        $status     = $grammar->wrap('status');
        $cases      = [];
        $bindings   = [];
        $filters    = [];
        if ($voidUuids !== []) {
            $placeholders = implode(', ', array_fill(0, count($voidUuids), '?'));
            $match        = $uuidColumn . ' IN (' . $placeholders . ') AND lower(' . $status . ')'
                . " NOT IN ('void', 'voided', 'cancelled', 'canceled')";
            $cases[]      = 'WHEN ' . $match . " THEN 'void'";
            $filters[]    = '(' . $match . ')';
            foreach ($voidUuids as $uuid) {
                $bindings[] = $uuid;
            }
        }
        if ($sentUuids !== []) {
            $placeholders = implode(', ', array_fill(0, count($sentUuids), '?'));
            $match        = $uuidColumn . ' IN (' . $placeholders . ') AND lower(' . $status . ')'
                . " IN ('paid', 'partial')";
            $cases[]      = 'WHEN ' . $match . " THEN 'sent'";
            $filters[]    = '(' . $match . ')';
            foreach ($sentUuids as $uuid) {
                $bindings[] = $uuid;
            }
        }

        return [$cases, $filters, $bindings];
    }

    /**
     * @param array<int, string> $voidUuids
     * @param array<int, string> $sentUuids
     * @param array<int, string> $cases
     * @param array<int, string> $filters
     * @param array<int, mixed>  $bindings
     */
    private function runInvoiceStatusUpdate(
        \Illuminate\Database\Connection $connection,
        \Illuminate\Database\Grammar $grammar,
        string $tableName,
        string $companyUuid,
        array $voidUuids,
        array $sentUuids,
        array $cases,
        array $filters,
        array $bindings,
    ): void {
        $table           = $grammar->wrapTable($tableName);
        $uuidColumn      = $grammar->wrap('uuid');
        $status          = $grammar->wrap('status');
        $whereBindings   = $bindings;
        $bindings[]      = Carbon::now()->toDateTimeString();
        $bindings[]      = $companyUuid;
        $all             = array_values(array_unique(array_merge($voidUuids, $sentUuids)));
        $allPlaceholders = implode(', ', array_fill(0, count($all), '?'));
        foreach ($all as $uuid) {
            $bindings[] = $uuid;
        }
        foreach ($whereBindings as $binding) {
            $bindings[] = $binding;
        }
        $sql = 'UPDATE ' . $table
            . ' SET ' . $status . ' = CASE ' . implode(' ', $cases) . ' ELSE ' . $status . ' END, '
            . $grammar->wrap('updated_at') . ' = ?'
            . ' WHERE ' . $grammar->wrap('company_uuid') . ' = ? AND ' . $grammar->wrap('deleted_at') . ' IS NULL'
            . ' AND ' . $uuidColumn . ' IN (' . $allPlaceholders . ')'
            . ' AND (' . implode(' OR ', $filters) . ')';
        try {
            $connection->update($sql, $bindings);
        } catch (\Throwable $exception) {
            if ($this->isUnavailableStorage($exception) === true) {
                return;
            }

            throw $exception;
        }
    }

    /**
     * Wallets that still hold money are not closed when QuickBooks deletes their account:
     * closing them would hide a balance. They stay open and linked, and the log says why.
     * When the balance cannot be read, every wallet is kept.
     *
     * @param array<int, string> $uuids
     *
     * @return array<int, string>
     */
    private function walletsHoldingBalance(string $companyUuid, array $uuids): array
    {
        $class = 'Fleetbase\\Ledger\\Models\\Wallet';
        $uuids = array_values(array_filter($uuids, static fn (string $uuid): bool => $uuid !== ''));
        if ($uuids === [] || $companyUuid === '' || class_exists($class) === false) {
            return [];
        }

        try {
            $held = array_map(
                static fn (mixed $uuid): string => (string) $uuid,
                $class::query()
                    ->where('company_uuid', $companyUuid)
                    ->whereIn('uuid', $uuids)
                    ->where('balance', '!=', 0)
                    ->pluck('uuid')
                    ->all()
            );
        } catch (\Throwable $exception) {
            SafeLog::warning('QuickBooks deleted a wallet account, but the wallet balance could not be read, '
                . 'so the wallet stays open.', [
                    'company_uuid' => $companyUuid,
                    'wallet_uuids' => $uuids,
                    'exception'    => $exception::class,
                ]);

            return $uuids;
        }
        if ($held !== []) {
            SafeLog::warning('QuickBooks deleted a wallet account, but the wallet still holds a balance, '
                . 'so it stays open and linked.', [
                    'company_uuid' => $companyUuid,
                    'wallet_uuids' => $held,
                ]);
        }

        return $held;
    }

    /**
     * @param class-string         $class
     * @param array<int, string>   $uuids
     * @param array<string, mixed> $extra
     */
    private function retireMany(string $class, string $companyUuid, array $uuids, array $extra): void
    {
        if ($uuids === [] || $companyUuid === '' || class_exists($class) === false) {
            return;
        }

        try {
            $class::query()->where('company_uuid', $companyUuid)->whereIn('uuid', $uuids)->update(array_merge($extra, [
                'deleted_at' => Carbon::now(),
                'updated_at' => Carbon::now(),
            ]));
        } catch (\Throwable $exception) {
            if ($this->isUnavailableStorage($exception) === true) {
                return;
            }

            throw $exception;
        }
    }

    /**
     * @param array<int, string>                 $linkUuids
     * @param array<string, array<string, true>> $voidInvoicesByRealm
     */
    private function deleteRetiredLinks(string $companyUuid, array $linkUuids, array $voidInvoicesByRealm): void
    {
        if ($companyUuid === '' || ($linkUuids === [] && $voidInvoicesByRealm === [])) {
            return;
        }

        try {
            $links = Link::query()->where('company_uuid', $companyUuid);
            $links->where(function ($query) use ($linkUuids, $voidInvoicesByRealm): void {
                $started = false;
                if ($linkUuids !== []) {
                    $query->whereIn('uuid', $linkUuids);
                    $started = true;
                }
                foreach ($voidInvoicesByRealm as $realm => $ids) {
                    $invoiceUuids = array_keys($ids);
                    if ($invoiceUuids === []) {
                        continue;
                    }
                    $method = $started === true ? 'orWhere' : 'where';
                    $query->{$method}(function ($inner) use ($realm, $invoiceUuids): void {
                        $inner->where('local_type', 'payment')->whereIn('local_uuid', $invoiceUuids);
                        if ($realm !== '') {
                            $inner->where('realm_id', $realm);
                        }
                    });
                    $started = true;
                }
            })->delete();
        } catch (\Illuminate\Database\QueryException $exception) {
            if ($this->isMissingTable($exception) === true) {
                return;
            }

            throw $exception;
        }
    }

    /**
     * @param array<string, array<int, string>> $uuidsByType
     */
    private function finishPendingMany(string $companyUuid, array $uuidsByType): void
    {
        $pairs = [];
        foreach ($uuidsByType as $type => $uuids) {
            foreach ($uuids as $uuid) {
                if ($uuid !== '') {
                    $pairs[] = [(string) $type, $uuid];
                }
            }
        }
        if ($companyUuid === '' || $pairs === []) {
            return;
        }

        try {
            PendingSync::query()
                ->where('company_uuid', $companyUuid)
                ->where('status', 'pending')
                ->where(function ($query) use ($pairs): void {
                    foreach ($pairs as $index => $pair) {
                        $method = $index === 0 ? 'where' : 'orWhere';
                        $query->{$method}(function ($inner) use ($pair): void {
                            $inner->where('local_type', $pair[0])->where('local_uuid', $pair[1]);
                        });
                    }
                })
                ->update([
                    'status'     => 'done',
                    'updated_at' => Carbon::now(),
                ]);
        } catch (\Illuminate\Database\QueryException $exception) {
            if ($this->isMissingTable($exception) === true) {
                return;
            }

            throw $exception;
        }
    }
}
