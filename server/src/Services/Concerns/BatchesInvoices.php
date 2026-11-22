<?php

namespace Fleetbase\Quickbooks\Services\Concerns;

use Fleetbase\Quickbooks\Services\SyncEngine;
use Fleetbase\Quickbooks\Services\SyncLedger;
use Fleetbase\Quickbooks\Support\QuickBooksException;
use Fleetbase\Quickbooks\Support\ReservedDocNumbers;

/**
 * SyncEngine's invoice blocks: sync the customers a block needs, read its QuickBooks invoices
 * and reserve its invoice numbers up front, then keep what the batch wrote. Split from
 * SyncsInvoices to keep each file readable.
 */
trait BatchesInvoices
{
    /**
     * The invoice an earlier create of this Fleetbase invoice saved under the number reserved for
     * it, when the answer to that create was lost. It is linked instead of created a second time.
     * An invoice under that number for another customer, or linked to another Fleetbase invoice,
     * is not this one's. Fleetbase created it, so with $ownsTax the link is marked as one whose
     * tax Fleetbase works out, as the create would have marked it.
     *
     * @param array<string, mixed> $connection
     *
     * @return array<string, mixed>|null
     */
    private function claimReservedInvoice(
        SyncLedger $ledger,
        array $connection,
        string $uuid,
        string $customerId,
        bool $ownsTax,
    ): ?array {
        $realmId = (string) $connection['realm_id'];
        $number  = ReservedDocNumbers::recall($realmId, $uuid);
        if ($number === null) {
            return null;
        }
        $existing = $this->client->findInvoiceByDocNumber($connection, $number);
        if (is_array($existing) === false) {
            return null;
        }
        $remoteId = trim((string) ($existing['Id'] ?? ''));
        if (
            $remoteId === ''
            || $this->invoiceCustomerMatches($existing, $customerId) === false
            || $ledger->remoteIdsLinked($realmId, 'Invoice', [$remoteId], $uuid) !== []
        ) {
            return null;
        }
        $this->putMatchedLink($ledger, $connection, 'invoice', $uuid, 'Invoice', $existing);
        if ($ownsTax === true) {
            $this->markOwnTax($ledger, $connection, $uuid);
        }
        if ($this->invoiceById !== null) {
            $this->invoiceById[$remoteId] = $existing;
        }

        return $ledger->link((string) $connection['company_uuid'], $realmId, 'invoice', $uuid);
    }

    /**
     * @param array<string, mixed>             $connection
     * @param array<int, array<string, mixed>> $rows
     * @param array<string, mixed>             $settings
     * @param array<string, mixed>             $batch
     */
    private function syncInvoiceBlock(
        SyncLedger $ledger,
        array &$connection,
        array $rows,
        array $settings,
        array &$batch,
        int $now,
        string $trigger,
    ): bool {
        if ($this->entityEnabled($settings, 'invoice') === false) {
            return $this->recordDisabledRows($ledger, $connection, $rows, $batch, $trigger, true);
        }
        $needed = $this->invoiceBlockCustomers($ledger, $connection, $rows);
        if ($this->prepareInvoiceCustomers($ledger, $connection, $needed, $settings, $batch, $now, $trigger) === true) {
            return true;
        }

        try {
            $this->openInvoiceBlock($ledger, $connection, $rows, $settings);

            return $this->syncBufferedBlock(
                $ledger,
                $connection,
                $rows,
                $settings,
                $batch,
                $now,
                $trigger,
                true,
                'invoice'
            );
        } catch (QuickBooksException $exception) {
            if ($exception->isUnauthorized() === true) {
                $this->tokenRejected($ledger, (string) $connection['company_uuid']);
                $batch['failed']++;

                return true;
            }
            if ($exception->isRateLimit() === true) {
                $this->failure($ledger, $connection, $rows[0], $exception, $settings, $now);
                $error = $this->exceptionError($exception);
                $this->recordRow($ledger, $connection, $rows[0], 'failed', $batch, $trigger, $error);

                return true;
            }

            throw $exception;
        } finally {
            $this->forgetInvoiceBlock();
        }
    }

    /**
     * Drop what one invoice block read and reserved.
     */
    /**
     * Open the per-block caches that forgetInvoiceBlock closes again, and read what the block needs.
     *
     * @param array<string, mixed>             $connection
     * @param array<int, array<string, mixed>> $rows
     * @param array<string, mixed>             $settings
     */
    private function openInvoiceBlock(SyncLedger $ledger, array $connection, array $rows, array $settings): void
    {
        $this->customersPrepared    = true;
        $this->serviceItemByCompany = [];
        $this->serviceItemErrors    = [];
        $this->serviceItemChecked   = false;
        $this->reservedDocNumbers   = null;
        $this->salesTaxByCompany    = [];
        $this->taxCustomers         = [];
        $this->taxItems             = [];
        $this->prefetchInvoices($connection, $ledger, $rows);
        $this->prefetchTaxCustomers($connection, $ledger, $rows);
        $this->prefetchPayments($connection, $ledger, $rows);
        $this->reserveInvoiceDocNumbers($ledger, $connection, $rows, $settings);
    }

    private function forgetInvoiceBlock(): void
    {
        $this->invoiceById          = null;
        $this->invoiceByDoc         = null;
        $this->invoiceReadFailed    = [];
        $this->invoiceDocReadFailed = [];
        $this->reservedDocNumbers   = null;
        $this->blockPayments        = null;
        $this->paymentPrefetchIds   = null;
        $this->paymentById          = null;
        $this->paymentReadFailed    = [];
        $this->serviceItemByCompany = null;
        $this->serviceItemErrors    = null;
        $this->serviceItemChecked   = null;
        $this->customersPrepared    = false;
        $this->customerLookupFailed = [];
        $this->salesTaxByCompany    = null;
        $this->taxCustomers         = null;
        $this->taxItems             = null;
        $this->rowNotes             = [];

        $this->customerCreateCurrency = [];
    }

    /**
     * The customers of the block's invoices that have no QuickBooks link yet, as pending rows by
     * uuid. Each is created in the currency of its first invoice.
     *
     * @param array<string, mixed>             $connection
     * @param array<int, array<string, mixed>> $rows
     *
     * @return array<string, array<string, mixed>>
     */
    private function invoiceBlockCustomers(SyncLedger $ledger, array $connection, array $rows): array
    {
        $companyUuid = (string) $connection['company_uuid'];
        $needed      = [];
        foreach ($rows as $row) {
            $invoice = $ledger->invoices[(string) ($row['local_uuid'] ?? '')] ?? null;
            if (is_array($invoice) === false) {
                continue;
            }
            $customerUuid = (string) ($invoice['customer_uuid'] ?? '');
            if ($customerUuid === '') {
                continue;
            }
            if ($ledger->link($companyUuid, (string) $connection['realm_id'], 'customer', $customerUuid) === null) {
                $this->customerCreateCurrency[$customerUuid] ??= (string) ($invoice['currency'] ?? '');
                $needed[$customerUuid] = [
                    'company_uuid' => $companyUuid,
                    'local_type'   => 'customer',
                    'local_uuid'   => $customerUuid,
                    'status'       => 'pending',
                    'attempts'     => 0,
                ];
            }
        }

        return $needed;
    }

    /**
     * Sync the block's unlinked customers before its invoices. True when the run must stop.
     *
     * @param array<string, mixed>                $connection
     * @param array<string, array<string, mixed>> $needed
     * @param array<string, mixed>                $settings
     * @param array<string, mixed>                $batch
     */
    private function prepareInvoiceCustomers(
        SyncLedger $ledger,
        array &$connection,
        array $needed,
        array $settings,
        array &$batch,
        int $now,
        string $trigger,
    ): bool {
        if ($this->entityPaused($settings, 'customer') === true || $needed === []) {
            return false;
        }
        if (count($needed) > 1) {
            $rows = array_values($needed);

            return $this->syncCustomerBlock($ledger, $connection, $rows, $settings, $batch, $now, $trigger, false);
        }
        $only = (string) array_key_first($needed);
        try {
            // No pending row drives this customer, so its key is the record alone. A lost create
            // is still found again by email or name, and QuickBooks refuses a second customer of
            // the same name (customers are made inactive, never deleted).
            $customerOutcome = $this->client->withWriteKey(
                'customer:' . $only,
                fn (): string => $this->syncCustomer($ledger, $connection, $only, $settings)
            );
        } catch (QuickBooksException $exception) {
            if ($exception->isUnauthorized() === true) {
                $this->tokenRejected($ledger, (string) $connection['company_uuid']);
                $batch['failed']++;

                return true;
            }
            if ($exception->isRateLimit() === true) {
                $this->failure($ledger, $connection, $needed[$only], $exception, $settings, $now);

                return true;
            }
            $this->customerLookupFailed[$only] = $exception;
            $customerOutcome                   = 'failed';
        }
        if ($customerOutcome === 'failed' && isset($this->customerLookupFailed[$only]) === false) {
            $this->lastError ??= 'quickbooks.messages.invoice-customer-failed';
        }

        return false;
    }

    /**
     * Invoice writes are durable before payment reads begin. A later payment failure
     * therefore cannot leave an invoice that QuickBooks created or updated unlinked.
     *
     * @param array<string, array{
     *     row: array<string, mixed>, outcome: string, error: string|null, permanent: bool
     * }> $planned
     * @param array<string, array{
     *     ok: bool, body: array<string, mixed>, rows: array<int, array<string, mixed>>,
     *     error: string|null, status: int, halt: bool
     * }> $results
     * @param array<string, mixed> $connection
     * @param array<string, mixed> $settings
     */
    private function persistSuccessfulInvoiceWrites(
        SyncLedger $ledger,
        array $connection,
        array $planned,
        array $results,
        array $settings,
    ): void {
        $buffered               = $this->bufferedLineMaps;
        $this->bufferedLineMaps = [];
        $this->refusedTaxRows   = [];
        foreach ($planned as $uuid => $plan) {
            $result = $results['invoice:' . $uuid] ?? null;
            if (is_array($result) === false || empty($result['ok']) === true) {
                continue;
            }
            $body = $result['body'] ?? null;
            if (is_array($body) === false || ($body['Id'] ?? '') === '') {
                continue;
            }

            $lines   = $buffered[(string) $uuid] ?? null;
            $outcome = $plan['outcome'];
            $this->persistInvoiceWrite($ledger, $connection, (string) $uuid, $outcome, $body, $lines, $settings);
        }
    }

    /**
     * Keep what one batched invoice write did: its link, its line ids, the QuickBooks invoice
     * number of a create, and the tax.
     *
     * @param array<string, mixed> $connection
     * @param array<string, mixed> $body
     * @param array{
     *     invoice: string, payload: array<string, mixed>, cleared: array<int, string>, edited?: bool, owns?: bool
     * }|null $lines the line map buffered for this invoice
     * @param array<string, mixed> $settings
     */
    private function persistInvoiceWrite(
        SyncLedger $ledger,
        array $connection,
        string $uuid,
        string $outcome,
        array $body,
        ?array $lines,
        array $settings,
    ): void {
        $this->putInvoiceLink($ledger, $connection, $uuid, $body, $settings);
        $this->rememberWrittenInvoice($body);
        if ($lines !== null) {
            $invoiceId = $lines['invoice'] !== '' ? $lines['invoice'] : (string) $body['Id'];
            $cleared   = $lines['cleared'];
            $this->recordPushedLines($ledger, $uuid, $connection, $invoiceId, $lines['payload'], $body, $cleared);
        }
        if ($outcome === 'created') {
            $this->copyInvoiceNumber($ledger, $uuid, $body, (string) ($settings['invoice_reference'] ?? 'fleetbase'));
        }
        $edited = ($lines['edited'] ?? false) === true;
        $owns   = ($lines['owns'] ?? false) === true;
        $copies = $edited === true || $this->copiesTaxAfterPush($settings, $outcome) === true;
        if ($owns === false && $copies === true) {
            $this->copyQuickBooksTax($ledger, $connection, $uuid, $body);
        }
        if ($owns === true && $lines !== null) {
            $this->markOwnTax($ledger, $connection, $uuid);
            $refused = $this->refusedOwnTax($ledger, $connection, $uuid, $lines['payload'], $body);
            if ($refused !== null) {
                $this->refusedTaxRows[$uuid] = $refused;
            }
        }
    }

    /**
     * One latest-invoice query and batched existence checks for every create in this block.
     * A single create leaves the reservation empty so syncInvoice can use one lookup.
     *
     * @param array<string, mixed>             $connection
     * @param array<int, array<string, mixed>> $rows
     * @param array<string, mixed>             $settings
     */
    private function reserveInvoiceDocNumbers(SyncLedger $ledger, array $connection, array $rows, array $settings): void
    {
        $this->reservedDocNumbers = null;
        if ($this->reservesInvoiceNumbers($connection, $settings) === false) {
            return;
        }

        $creates = [];
        foreach ($rows as $row) {
            $uuid = (string) ($row['local_uuid'] ?? '');
            if ($uuid !== '' && $this->invoiceCreateNeedsDocNumber($ledger, $connection, $uuid) === true) {
                $creates[] = $uuid;
            }
        }
        if (count($creates) <= 1 || $this->serviceItemReady($ledger, $connection) === false) {
            return;
        }

        $numbers  = $this->client->nextInvoiceDocNumbers($connection, count($creates));
        $reserved = [];
        foreach ($creates as $index => $uuid) {
            $number = $numbers[$index] ?? '';
            if ($number !== '') {
                $reserved[$uuid] = $number;
            }
        }
        $this->reservedDocNumbers = $reserved;
    }

    /**
     * Whether QuickBooks numbers the invoices this block sends: QuickBooks reference, invoices
     * sent, and custom transaction numbers on.
     *
     * @param array<string, mixed> $connection
     * @param array<string, mixed> $settings
     */
    private function reservesInvoiceNumbers(array $connection, array $settings): bool
    {
        if ((string) ($settings['invoice_reference'] ?? 'fleetbase') !== 'quickbooks') {
            return false;
        }
        if ($this->entityEnabled($settings, 'invoice') === false) {
            return false;
        }
        if ($this->pushesRemote($this->direction($settings, 'invoice')) === false) {
            return false;
        }

        return $this->customTxnNumbers($connection);
    }

    /**
     * Whether the service item invoice lines use is there, so creates can go ahead.
     *
     * @param array<string, mixed> $connection
     */
    private function serviceItemReady(SyncLedger $ledger, array $connection): bool
    {
        try {
            return $this->serviceItemId($ledger, $connection) !== '';
        } catch (QuickBooksException $exception) {
            if ($exception->isUnauthorized() === true || $exception->isRateLimit() === true) {
                throw $exception;
            }

            // Each create fails on its own with this error; the rest of the block still runs.
            return false;
        }
    }

    /**
     * @param array<string, mixed> $connection
     */
    private function invoiceCreateNeedsDocNumber(SyncLedger $ledger, array $connection, string $uuid): bool
    {
        $invoice = $ledger->invoices[$uuid] ?? null;
        if (is_array($invoice) === false) {
            return false;
        }

        $status = (string) ($invoice['status'] ?? '');
        $closed = in_array($status, ['void', 'voided', 'cancelled', 'canceled', 'deleted'], true);
        if ($closed === true || empty($invoice['deleted_at']) === false) {
            return false;
        }

        $customerUuid = (string) ($invoice['customer_uuid'] ?? '');
        $companyUuid  = (string) $connection['company_uuid'];
        $realm        = (string) $connection['realm_id'];
        $customerLink = $customerUuid === '' ? null : $ledger->link($companyUuid, $realm, 'customer', $customerUuid);
        if ($customerLink === null) {
            return false;
        }

        // A linked invoice is not a first create, even when the QuickBooks read is empty.
        if ($ledger->link($companyUuid, $realm, 'invoice', $uuid) !== null) {
            return false;
        }

        $number     = trim((string) ($invoice['number'] ?? ''));
        $existing   = ($number !== '' && $this->invoiceByDoc !== null) ? ($this->invoiceByDoc[$number] ?? null) : null;
        $customerId = (string) ($customerLink['qbo_id'] ?? '');
        if (is_array($existing) === true && $this->invoiceCustomerMatches($existing, $customerId) === true) {
            return false;
        }

        return true;
    }

    /**
     * The QuickBooks ids of linked invoices and the doc numbers of unlinked ones with a customer.
     *
     * @param array<string, mixed>             $connection
     * @param array<int, array<string, mixed>> $rows
     *
     * @return array{0: array<int, string>, 1: array<int, string>}
     */
    private function invoiceLookupKeys(array $connection, SyncLedger $ledger, array $rows): array
    {
        $ids         = [];
        $docs        = [];
        $companyUuid = (string) $connection['company_uuid'];
        $realm       = (string) $connection['realm_id'];
        foreach ($rows as $row) {
            $uuid     = (string) ($row['local_uuid'] ?? '');
            $invoice  = $ledger->invoices[$uuid] ?? null;
            $link     = $ledger->link($companyUuid, $realm, 'invoice', $uuid);
            $customer = is_array($invoice) === true ? trim((string) ($invoice['customer_uuid'] ?? '')) : '';
            $number   = is_array($invoice) === true ? trim((string) ($invoice['number'] ?? '')) : '';
            if (is_array($link) === true && (string) ($link['qbo_id'] ?? '') !== '') {
                $ids[] = (string) $link['qbo_id'];
            } elseif ($customer !== '' && $number !== '') {
                $docs[] = $number;
            }
        }

        return [array_values(array_unique($ids)), array_values(array_unique($docs))];
    }

    /**
     * @param array<string, mixed>             $connection
     * @param array<int, array<string, mixed>> $rows
     */
    private function prefetchInvoices(array $connection, SyncLedger $ledger, array $rows): void
    {
        $this->invoiceById          = [];
        $this->invoiceByDoc         = [];
        [$ids, $docs]               = $this->invoiceLookupKeys($connection, $ledger, $rows);
        $this->invoiceReadFailed    = [];
        $this->invoiceDocReadFailed = [];
        if ($ids !== []) {
            $loaded = $this->queryWhereIn(
                $connection,
                'Invoice',
                'Id',
                $ids,
                fn (string $invoiceId): ?array => $this->client->getInvoice($connection, $invoiceId)
            );
            foreach ($loaded['rows'] as $remote) {
                $this->rememberPrefetchedInvoice($remote);
            }
            $this->invoiceReadFailed = $loaded['failed'];
        }
        if ($docs !== []) {
            $loaded = $this->queryWhereIn(
                $connection,
                'Invoice',
                'DocNumber',
                $docs,
                fn (string $doc): ?array => $this->client->findInvoiceByDocNumber($connection, $doc)
            );
            foreach ($loaded['rows'] as $remote) {
                $this->rememberPrefetchedInvoice($remote);
            }
            $this->invoiceDocReadFailed = $loaded['failed'];
        }
    }

    /**
     * @param array<string, mixed> $remote
     */
    private function rememberWrittenInvoice(array $remote): void
    {
        // A block reads its payments' invoices from this cache, so it holds what was just written.
        if ($this->blockPayments !== null) {
            $this->invoiceById ??= [];
        }
        $this->rememberPrefetchedInvoice($remote);
    }

    /**
     * @param array<string, mixed> $remote
     */
    private function rememberPrefetchedInvoice(array $remote): void
    {
        $invoiceId = trim((string) ($remote['Id'] ?? ''));
        if ($invoiceId !== '' && $this->invoiceById !== null) {
            $this->invoiceById[$invoiceId] = $remote;
        }
        $doc = trim((string) ($remote['DocNumber'] ?? ''));
        if ($doc !== '' && $this->invoiceByDoc !== null) {
            $this->invoiceByDoc[$doc] = $remote;
        }
    }
}
