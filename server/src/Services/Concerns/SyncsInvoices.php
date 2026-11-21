<?php

namespace Fleetbase\Quickbooks\Services\Concerns;

use Fleetbase\Quickbooks\Services\SyncEngine;
use Fleetbase\Quickbooks\Services\SyncLedger;
use Fleetbase\Quickbooks\Support\InvoiceMapper;
use Fleetbase\Quickbooks\Support\ReservedDocNumbers;
use Fleetbase\Quickbooks\Support\Translation;

/**
 * SyncEngine's invoice sync: sending Ledger invoices to QuickBooks, invoice numbers, line
 * tracking, customer and currency checks, and copying QuickBooks invoice changes back. It shares
 * the engine's state, so it lives here only to keep SyncEngine.php readable.
 *
 * @phpstan-import-type InvoicePlan from SyncEngine
 */
trait SyncsInvoices
{
    /**
     * Copy a QuickBooks invoice change from a webhook. When Fleetbase works out the tax of an
     * invoice whose QuickBooks amounts include tax, nothing is copied: the invoice is queued, and
     * its sync fails and says why (rememberAppliedToken, syncInvoice).
     *
     * @param array<string, mixed> $connection
     * @param array<string, mixed> $settings
     * @param array<string, mixed> $link
     * @param array<string, mixed> $remote
     */
    private function applyRemoteInvoice(
        SyncLedger $ledger,
        array $connection,
        array $settings,
        array $link,
        array $remote,
    ): void {
        $localUuid = (string) ($link['local_uuid'] ?? '');
        $owns      = $this->ownsTax($settings, $link);
        if ($owns === true && InvoiceMapper::taxInclusive($remote) === true) {
            return;
        }
        $reference = (string) ($settings['invoice_reference'] ?? 'fleetbase');
        $realmId   = (string) $connection['realm_id'];
        $clears    = $this->copiesClears($settings, 'invoice', $link, $remote);
        $codes     = $this->taxCodesFor($connection, $remote);
        $this->applyInvoiceFromRemote($ledger, $localUuid, $remote, $reference, $realmId, $clears, $codes, $owns);
        $this->applyPaymentStatus($ledger, $localUuid);
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function dueInvoicePending(SyncLedger $ledger, string $companyUuid, int $now, int $limit): array
    {
        $pending = [];
        foreach ($ledger->pending as $row) {
            if (($row['company_uuid'] ?? '') !== $companyUuid || ($row['local_type'] ?? '') !== 'invoice' || ($row['status'] ?? '') !== 'pending') {
                continue;
            }
            $next = $row['next_attempt_at'] ?? null;
            if ($next !== null && (int) $next > $now) {
                continue;
            }
            $pending[] = $row;
            if (count($pending) >= $limit) {
                break;
            }
        }

        return $pending;
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function reconcileInvoiceRows(SyncLedger $ledger, string $companyUuid): array
    {
        $rows = [];
        $seen = [];
        foreach ($ledger->invoices as $invoice) {
            if (($invoice['company_uuid'] ?? '') !== $companyUuid || (string) ($invoice['status'] ?? 'draft') === 'draft') {
                continue;
            }
            $uuid        = (string) $invoice['uuid'];
            $seen[$uuid] = true;
            $rows[]      = ['company_uuid' => $companyUuid, 'local_type' => 'invoice', 'local_uuid' => $uuid, 'status' => 'pending', 'attempts' => 0];
        }
        foreach ($ledger->links as $link) {
            if (($link['company_uuid'] ?? '') !== $companyUuid || ($link['local_type'] ?? '') !== 'invoice') {
                continue;
            }
            $uuid = (string) ($link['local_uuid'] ?? '');
            if ($uuid === '' || isset($seen[$uuid]) === true || isset($ledger->invoices[$uuid]) === false) {
                continue;
            }
            $seen[$uuid] = true;
            $rows[]      = ['company_uuid' => $companyUuid, 'local_type' => 'invoice', 'local_uuid' => $uuid, 'status' => 'pending', 'attempts' => 0];
        }

        return $rows;
    }

    /**
     * @param array<string, mixed> $invoice
     */
    private static function voidedInvoice(array $invoice): bool
    {
        $status = (string) ($invoice['status'] ?? '');

        return in_array($status, ['void', 'voided', 'cancelled', 'canceled', 'deleted'], true) === true
            || empty($invoice['deleted_at']) === false;
    }

    /**
     * @param array<string, mixed> $connection
     * @param array<string, mixed> $settings
     */
    private function syncInvoice(SyncLedger $ledger, array $connection, string $uuid, array $settings): string
    {
        if ($this->entityPaused($settings, 'invoice') === true) {
            $this->closeSkipped = true;

            return 'skipped';
        }

        $invoice = $ledger->invoices[$uuid] ?? null;
        if ($invoice === null) {
            $this->lastError = 'quickbooks.messages.invoice-missing';

            return 'failed';
        }

        $customerUuid = (string) ($invoice['customer_uuid'] ?? '');
        $customerLink = $this->invoiceCustomerLink($ledger, $connection, $customerUuid, $invoice, $settings);
        if ($customerLink === null) {
            return 'failed';
        }

        $voided = self::voidedInvoice($invoice);
        $itemId = $voided === true ? '' : $this->serviceItemId($ledger, $connection);
        if ($voided === false && $itemId === '') {
            $this->lastError = 'quickbooks.messages.invoice-item-missing';

            return 'failed';
        }

        $plan = $this->invoicePlan(
            $ledger,
            $connection,
            $uuid,
            $settings,
            $customerUuid,
            $customerLink,
            $itemId,
            $voided
        );
        if ($voided === true) {
            return $this->voidRemoteInvoice($connection, $uuid, $plan);
        }

        return $this->syncPlannedInvoice($ledger, $connection, $uuid, $plan);
    }

    /**
     * Create, update or align the QuickBooks invoice of a live Fleetbase invoice.
     *
     * @param array<string, mixed> $connection
     * @param InvoicePlan          $plan
     */
    private function syncPlannedInvoice(SyncLedger $ledger, array $connection, string $uuid, array $plan): string
    {
        $link   = $plan['link'];
        $remote = $link === null ? null : $this->readLinkedInvoice($connection, (string) $link['qbo_id']);
        // An empty read means QuickBooks deleted this linked invoice. Do not create a replacement.
        // A failed read is stored separately and throws above, so it is not an empty result.
        if ($remote === null && $link !== null) {
            return 'aligned';
        }
        if ($remote === null) {
            return $this->createRemoteInvoice($ledger, $connection, $uuid, $plan);
        }

        return $this->updateRemoteInvoice($ledger, $connection, $uuid, $plan, $remote);
    }

    /**
     * The QuickBooks link of the invoice's customer, syncing the customer first when it has none.
     * Null when there is none, with lastError saying why.
     *
     * @param array<string, mixed> $connection
     * @param array<string, mixed> $invoice
     * @param array<string, mixed> $settings
     *
     * @return array<string, mixed>|null
     */
    private function invoiceCustomerLink(
        SyncLedger $ledger,
        array $connection,
        string $customerUuid,
        array $invoice,
        array $settings,
    ): ?array {
        $companyUuid = (string) $connection['company_uuid'];
        $realmId     = (string) $connection['realm_id'];
        if ($customerUuid !== '' && isset($this->customerLookupFailed[$customerUuid]) === true) {
            throw $this->customerLookupFailed[$customerUuid];
        }
        if ($customerUuid !== '' && $ledger->link($companyUuid, $realmId, 'customer', $customerUuid) === null) {
            if ($this->entityPaused($settings, 'customer') === true) {
                $this->lastError = 'quickbooks.messages.invoice-customer-off';

                return null;
            }
            if ($this->customersPrepared === true) {
                $this->lastError = 'quickbooks.messages.invoice-customer-failed';

                return null;
            }
            $currency        = (string) ($invoice['currency'] ?? '');
            $customerOutcome = $this->syncCustomerFor($ledger, $connection, $customerUuid, $settings, $currency);
            if ($customerOutcome === 'failed') {
                $this->lastError ??= 'quickbooks.messages.invoice-customer-failed';

                return null;
            }
        }

        $customerLink = $ledger->link($companyUuid, $realmId, 'customer', $customerUuid);
        if ($customerLink === null) {
            $this->lastError ??= 'quickbooks.messages.invoice-customer-invalid';
        }

        return $customerLink;
    }

    /**
     * How this invoice syncs: its payload, its stored lines, and its link, matched by invoice
     * number or claimed from a reserved number when it had none.
     *
     * @param array<string, mixed> $connection
     * @param array<string, mixed> $settings
     * @param array<string, mixed> $customerLink
     *
     * @return InvoicePlan
     */
    private function invoicePlan(
        SyncLedger $ledger,
        array $connection,
        string $uuid,
        array $settings,
        string $customerUuid,
        array $customerLink,
        string $itemId,
        bool $voided,
    ): array {
        $copy      = $this->copiesRemote($this->direction($settings, 'invoice'));
        $push      = $this->pushesRemote($this->direction($settings, 'invoice'));
        $stored    = $this->storedLineIds($ledger, $connection, $uuid, $voided);
        $lineItems = $voided === true || $push === false
            ? []
            : $this->lineItemIds($ledger, $connection, $uuid, $itemId);
        $managed   = $this->managedItems($ledger, $connection, $itemId);
        $invoice   = $ledger->invoices[$uuid];
        $reference = (string) ($settings['invoice_reference'] ?? 'fleetbase');
        $conflict  = (string) ($settings['invoice_conflict'] ?? 'fleetbase');
        $payload   = $this->plannedInvoicePayload($invoice, $customerLink, $itemId, $lineItems, $reference);
        // The link before this sync adopts a QuickBooks invoice: a new match has no baseline.
        $companyUuid = (string) $connection['company_uuid'];
        $baseline    = $ledger->link($companyUuid, (string) $connection['realm_id'], 'invoice', $uuid);
        $link        = $baseline ?? ($voided === true ? null : $this->adoptedInvoiceLink(
            $ledger,
            $connection,
            $uuid,
            $settings,
            $invoice,
            $customerLink
        ));

        return [
            'settings'     => $settings,
            'copy'         => $copy,
            'push'         => $push,
            'reference'    => $reference,
            'conflict'     => $conflict,
            'pushClears'   => $this->sendsClears($conflict, $push, $copy),
            'itemId'       => $itemId,
            'customerUuid' => $customerUuid,
            'customerLink' => $customerLink,
            'stored'       => $stored,
            'lineItems'    => $lineItems,
            'managed'      => $managed,
            'payload'      => $payload,
            'baseline'     => $baseline,
            'link'         => $link,
        ];
    }

    /**
     * The link of an invoice that had none: the QuickBooks invoice with its number, or under
     * QuickBooks reference the one its reserved number made. Null when there is none.
     *
     * @param array<string, mixed> $connection
     * @param array<string, mixed> $settings
     * @param array<string, mixed> $invoice
     * @param array<string, mixed> $customerLink
     *
     * @return array<string, mixed>|null
     */
    private function adoptedInvoiceLink(
        SyncLedger $ledger,
        array $connection,
        string $uuid,
        array $settings,
        array $invoice,
        array $customerLink,
    ): ?array {
        $link = null;
        if (empty($invoice['number']) === false) {
            $number = (string) $invoice['number'];
            $link   = $this->matchInvoiceByDocNumber($ledger, $connection, $uuid, $number, $customerLink);
        }
        if ($link === null && (string) ($settings['invoice_reference'] ?? 'fleetbase') === 'quickbooks') {
            $link = $this->claimReservedInvoice(
                $ledger,
                $connection,
                $uuid,
                (string) $customerLink['qbo_id'],
                $this->fleetbaseWorksOutTax($settings)
            );
        }

        return $link;
    }

    /**
     * Link the invoice to the QuickBooks invoice with its number, when that invoice is on the
     * same customer. The new link, or null.
     *
     * @param array<string, mixed> $connection
     * @param array<string, mixed> $customerLink
     *
     * @return array<string, mixed>|null
     */
    private function matchInvoiceByDocNumber(
        SyncLedger $ledger,
        array $connection,
        string $uuid,
        string $number,
        array $customerLink,
    ): ?array {
        if (isset($this->invoiceDocReadFailed[$number]) === true) {
            throw $this->invoiceDocReadFailed[$number];
        }
        $existing = $this->invoiceByDoc !== null
            ? ($this->invoiceByDoc[$number] ?? null)
            : $this->client->findInvoiceByDocNumber($connection, $number);
        $customerId = (string) ($customerLink['qbo_id'] ?? '');
        if (is_array($existing) === false || $this->invoiceCustomerMatches($existing, $customerId) === false) {
            return null;
        }
        $this->putMatchedLink($ledger, $connection, 'invoice', $uuid, 'Invoice', $existing);
        $matchedId = trim((string) ($existing['Id'] ?? ''));
        if ($this->invoiceById !== null && $matchedId !== '') {
            $this->invoiceById[$matchedId] = $existing;
        }

        return $ledger->link((string) $connection['company_uuid'], (string) $connection['realm_id'], 'invoice', $uuid);
    }

    /**
     * @param array<string, mixed> $connection
     *
     * @return array<string, mixed>|null
     */
    private function readLinkedInvoice(array $connection, string $remoteId): ?array
    {
        if (isset($this->invoiceReadFailed[$remoteId]) === true) {
            throw $this->invoiceReadFailed[$remoteId];
        }

        return $this->invoiceById !== null
            ? ($this->invoiceById[$remoteId] ?? null)
            : $this->client->getInvoice($connection, $remoteId);
    }

    /**
     * A voided Fleetbase invoice voids its linked QuickBooks invoice.
     *
     * @param array<string, mixed> $connection
     * @param InvoicePlan          $plan
     */
    private function voidRemoteInvoice(array $connection, string $uuid, array $plan): string
    {
        $link = $plan['link'];
        if ($link === null) {
            return 'aligned';
        }
        $remote = $this->readLinkedInvoice($connection, (string) $link['qbo_id']);
        if ($remote === null || $plan['push'] === false) {
            return 'aligned';
        }
        if ($this->writeBuffer !== null) {
            $this->writeBuffer[] = [
                'bId'       => 'invoice:' . $uuid,
                'operation' => 'void',
                'entity'    => 'Invoice',
                'payload'   => ['Id' => (string) $remote['Id'], 'SyncToken' => (string) $remote['SyncToken']],
            ];

            return 'voided';
        }
        $this->client->voidInvoice($connection, (string) $remote['Id'], (string) $remote['SyncToken']);

        return 'voided';
    }

    /**
     * No QuickBooks invoice has this invoice: create one.
     *
     * @param array<string, mixed> $connection
     * @param InvoicePlan          $plan
     */
    private function createRemoteInvoice(SyncLedger $ledger, array $connection, string $uuid, array $plan): string
    {
        if ($plan['push'] === false) {
            $this->closeSkipped = true;

            return 'skipped';
        }
        $payload = $this->newInvoicePayload($ledger, $connection, $uuid, $plan);
        if ($payload === null) {
            return 'failed';
        }
        $owns = $this->fleetbaseWorksOutTax($plan['settings']);
        if ($this->writeBuffer !== null) {
            $this->writeBuffer[] = [
                'bId'       => 'invoice:' . $uuid,
                'operation' => 'create',
                'entity'    => 'Invoice',
                'payload'   => $payload,
            ];
            $this->bufferedLineMaps[$uuid] = [
                'invoice' => '',
                'payload' => $payload,
                'cleared' => [],
                'owns'    => $owns,
            ];

            return 'created';
        }

        return $this->writeNewInvoice($ledger, $connection, $uuid, $plan, $payload, $owns);
    }

    /**
     * The create payload: invoice number, tax plan and the tax of whichever side works it out.
     * Null when it cannot be sent, with lastError saying why.
     *
     * @param array<string, mixed> $connection
     * @param InvoicePlan          $plan
     *
     * @return array<string, mixed>|null
     */
    private function newInvoicePayload(SyncLedger $ledger, array $connection, string $uuid, array $plan): ?array
    {
        $payload = $plan['payload'];
        if ($this->rejectInvoiceCurrency($payload, $ledger, $connection) === true) {
            return null;
        }
        if ($plan['reference'] === 'quickbooks' && $this->customTxnNumbers($connection) === true) {
            $next = $this->reservedDocNumbers !== null
                ? ($this->reservedDocNumbers[$uuid] ?? null)
                : $this->client->nextInvoiceDocNumber($connection);
            if (is_string($next) === false || $next === '') {
                $this->lastError = 'quickbooks.messages.invoice-number-unconfirmed';

                return null;
            }
            $payload['DocNumber'] = $next;
            ReservedDocNumbers::remember((string) $connection['realm_id'], $uuid, $next);
        }
        if ($this->rejectLongDocNumber($payload) === true) {
            return null;
        }
        $customerId = (string) $plan['customerLink']['qbo_id'];
        $taxPlan    = $this->invoiceTaxPlan(
            $ledger,
            $connection,
            $uuid,
            $customerId,
            $plan['itemId'],
            $plan['lineItems']
        );
        $payload    = $this->invoices->withTax($payload, $taxPlan);
        if ($this->fleetbaseWorksOutTax($plan['settings']) === true) {
            return $this->withFleetbaseTax($ledger, $connection, $uuid, $payload, $taxPlan, []);
        }

        return $this->quickBooksTaxSendable($uuid, $payload, $connection) === true ? $payload : null;
    }

    /**
     * @param array<string, mixed> $connection
     * @param InvoicePlan          $plan
     * @param array<string, mixed> $payload
     */
    private function writeNewInvoice(
        SyncLedger $ledger,
        array $connection,
        string $uuid,
        array $plan,
        array $payload,
        bool $owns,
    ): string {
        $created = $this->client->createInvoice($connection, $payload);
        $this->rememberWrittenInvoice($created);
        $marker = $owns === true ? [self::TAX_SOURCE => self::FLEETBASE_TAX] : [];
        $ledger->putLink($marker + $this->linkFrom($connection, 'invoice', $uuid, 'Invoice', $created));
        $this->copyInvoiceNumber($ledger, $uuid, $created, $plan['reference']);
        $createdId = (string) ($created['Id'] ?? '');
        $this->recordPushedLines($ledger, $uuid, $connection, $createdId, $payload, $created, []);
        if ($plan['copy'] === true && $owns === false) {
            $this->copyQuickBooksTax($ledger, $connection, $uuid, $created);
        }
        $refused = $owns === true ? $this->refusedOwnTax($ledger, $connection, $uuid, $payload, $created) : null;
        if ($refused !== null) {
            $this->lastError = $refused;

            return 'failed';
        }

        return $this->withInvoicePayment($ledger, $connection, $uuid, $plan, $createdId, 'created');
    }

    /**
     * The outcome of an invoice sync with the outcome of its payment. In a batch the payment
     * is sent after the batch, so the outcome is returned as it is.
     *
     * @param array<string, mixed>      $connection
     * @param InvoicePlan               $plan
     * @param array<string, mixed>|null $invoice    the invoice to pay; the stored one when null
     */
    private function withInvoicePayment(
        SyncLedger $ledger,
        array $connection,
        string $uuid,
        array $plan,
        string $remoteId,
        string $outcome,
        ?array $invoice = null,
    ): string {
        if ($this->writeBuffer !== null) {
            return $outcome;
        }
        $customerId = (string) $plan['customerLink']['qbo_id'];
        $invoice ??= $ledger->invoices[$uuid];

        return $this->withPayment(
            $outcome,
            $this->syncPayment($ledger, $connection, $invoice, $customerId, $remoteId, $plan['settings'])
        );
    }

    /**
     * A foreign-currency invoice is not posted in the home currency. Without multi-currency
     * in QuickBooks the row fails with the currency message; the caller returns 'failed'.
     *
     * @param array<string, mixed> $payload
     * @param array<string, mixed> $connection
     */
    private function rejectInvoiceCurrency(array $payload, SyncLedger $ledger, array $connection): bool
    {
        $ref      = $payload['CurrencyRef'] ?? null;
        $currency = is_array($ref) === true ? (string) ($ref['value'] ?? '') : '';
        $error    = $this->invoiceCurrencyError($ledger, $connection, $currency)
            ?? $this->customerCurrencyError($ledger, $connection, $payload, $currency);
        if ($error === null) {
            return false;
        }
        $this->lastError = $error;

        return true;
    }

    /**
     * QuickBooks refuses the whole invoice when DocNumber is too long. Fail the row before
     * sending, with a message that names the number.
     *
     * @param array<string, mixed> $payload
     */
    private function rejectLongDocNumber(array $payload): bool
    {
        $number = trim((string) ($payload['DocNumber'] ?? ''));
        if (mb_strlen($number) <= self::DOC_NUMBER_MAX) {
            return false;
        }
        $this->lastError = Translation::encode('quickbooks.messages.invoice-number-too-long', [
            'number' => $number,
            'max'    => (string) self::DOC_NUMBER_MAX,
        ]);

        return true;
    }

    /**
     * @param array<string, mixed> $payload
     * @param array<string, mixed> $remote
     */
    private function keepBillAddrId(array &$payload, array $remote): void
    {
        if (isset($payload['BillAddr']) === false || is_array($payload['BillAddr']) === false) {
            return;
        }
        $bill = $remote['BillAddr'] ?? null;
        if (is_array($bill) === false) {
            return;
        }
        $addressId = trim((string) ($bill['Id'] ?? ''));
        if ($addressId !== '') {
            $payload['BillAddr']['Id'] = $addressId;
        }
    }

    /**
     * An empty QuickBooks description does not replace a Fleetbase description, unless QuickBooks
     * wins the whole account ($clears, see copiesClears).
     *
     * @param array<string, mixed> $remote
     *
     * @return array<string, mixed>
     */
    private function withoutEmptyDescription(array $remote, bool $clears = false): array
    {
        if ($clears === true) {
            $description           = (string) ($remote['Description'] ?? '');
            $remote['Description'] = trim($description) === '' ? '' : $description;

            return $remote;
        }
        if (trim((string) ($remote['Description'] ?? '')) === '') {
            unset($remote['Description']);
        }

        return $remote;
    }
}
