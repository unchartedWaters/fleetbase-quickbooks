<?php

namespace Fleetbase\Quickbooks\Services\Concerns;

use Fleetbase\Quickbooks\Services\SyncEngine;
use Fleetbase\Quickbooks\Services\SyncLedger;
use Fleetbase\Quickbooks\Support\ContentHash;
use Fleetbase\Quickbooks\Support\InvoiceMapper;
use Fleetbase\Quickbooks\Support\PaymentTokens;
use Fleetbase\Quickbooks\Support\QuickBooksChanges;
use Fleetbase\Quickbooks\Support\QuickBooksException;
use Fleetbase\Quickbooks\Support\ReservedDocNumbers;
use Fleetbase\Quickbooks\Support\Translation;

/**
 * SyncEngine's invoice sync: sending Ledger invoices to QuickBooks, invoice numbers, line
 * tracking, customer and currency checks, and copying QuickBooks invoice changes back. It shares
 * the engine's state, so it lives here only to keep SyncEngine.php readable.
 *
 * @phpstan-import-type RemovedLine from SyncEngine
 * @phpstan-import-type StoredLines from SyncEngine
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
        $direction = $this->direction($settings, 'invoice');
        if ($this->entityPaused($settings, 'invoice') === true) {
            $this->closeSkipped = true;

            return 'skipped';
        }

        $invoice = $ledger->invoices[$uuid] ?? null;
        if ($invoice === null) {
            $this->lastError = 'quickbooks.messages.invoice-missing';

            return 'failed';
        }

        $copy         = $this->copiesRemote($direction);
        $push         = $this->pushesRemote($direction);
        $customerUuid = (string) ($invoice['customer_uuid'] ?? '');
        if ($customerUuid !== '' && isset($this->customerLookupFailed[$customerUuid]) === true) {
            throw $this->customerLookupFailed[$customerUuid];
        }
        if ($customerUuid !== '' && $ledger->link((string) $connection['company_uuid'], (string) $connection['realm_id'], 'customer', $customerUuid) === null) {
            if ($this->entityPaused($settings, 'customer') === true) {
                $this->lastError = 'quickbooks.messages.invoice-customer-off';

                return 'failed';
            }
            if ($this->customersPrepared === true) {
                $this->lastError = 'quickbooks.messages.invoice-customer-failed';

                return 'failed';
            }
            $currency        = (string) ($invoice['currency'] ?? '');
            $customerOutcome = $this->syncCustomerFor($ledger, $connection, $customerUuid, $settings, $currency);
            if ($customerOutcome === 'failed') {
                $this->lastError ??= 'quickbooks.messages.invoice-customer-failed';

                return 'failed';
            }
        }

        $customerLink = $ledger->link((string) $connection['company_uuid'], (string) $connection['realm_id'], 'customer', $customerUuid);
        if ($customerLink === null) {
            $this->lastError ??= 'quickbooks.messages.invoice-customer-invalid';

            return 'failed';
        }

        $voided = self::voidedInvoice($invoice);
        $itemId = $voided === true ? '' : $this->serviceItemId($ledger, $connection);
        if ($voided === false && $itemId === '') {
            $this->lastError = 'quickbooks.messages.invoice-item-missing';

            return 'failed';
        }

        $stored     = $this->storedLineIds($ledger, $connection, $uuid, $voided);
        $lineItems  = $voided === true || $push === false
            ? []
            : $this->lineItemIds($ledger, $connection, $uuid, $itemId);
        $managed    = $this->managedItems($ledger, $connection, $itemId);
        $invoice    = $ledger->invoices[$uuid];
        $payload    = $this->invoices->withLineItems(
            $this->invoices->toQuickBooks($invoice, (string) $customerLink['qbo_id'], $itemId),
            $lineItems
        );
        $reference  = (string) ($settings['invoice_reference'] ?? 'fleetbase');
        $conflict   = (string) ($settings['invoice_conflict'] ?? 'fleetbase');
        $pushClears = $this->sendsClears($conflict, $push, $copy);
        if ($reference === 'quickbooks') {
            unset($payload['DocNumber']);
        }
        $link = $ledger->link((string) $connection['company_uuid'], (string) $connection['realm_id'], 'invoice', $uuid);
        // The link before this sync adopts a QuickBooks invoice: a new match has no baseline.
        $baseline = $link;

        if ($link === null && $voided === false && empty($invoice['number']) === false) {
            $number = (string) $invoice['number'];
            if (isset($this->invoiceDocReadFailed[$number]) === true) {
                throw $this->invoiceDocReadFailed[$number];
            }
            $existing = $this->invoiceByDoc !== null
                ? ($this->invoiceByDoc[$number] ?? null)
                : $this->client->findInvoiceByDocNumber($connection, $number);
            if (is_array($existing) === true && $this->invoiceCustomerMatches($existing, (string) ($customerLink['qbo_id'] ?? '')) === true) {
                $this->putMatchedLink($ledger, $connection, 'invoice', $uuid, 'Invoice', $existing);
                $link      = $ledger->link((string) $connection['company_uuid'], (string) $connection['realm_id'], 'invoice', $uuid);
                $matchedId = trim((string) ($existing['Id'] ?? ''));
                if ($this->invoiceById !== null && $matchedId !== '') {
                    $this->invoiceById[$matchedId] = $existing;
                }
            }
        }
        if ($link === null && $voided === false && $reference === 'quickbooks') {
            $link = $this->claimReservedInvoice(
                $ledger,
                $connection,
                $uuid,
                (string) $customerLink['qbo_id'],
                $this->fleetbaseWorksOutTax($settings)
            );
        }

        if ($voided === true) {
            if ($link === null) {
                return 'aligned';
            }
            $remoteId = (string) $link['qbo_id'];
            if (isset($this->invoiceReadFailed[$remoteId]) === true) {
                throw $this->invoiceReadFailed[$remoteId];
            }
            $remote = $this->invoiceById !== null
                ? ($this->invoiceById[$remoteId] ?? null)
                : $this->client->getInvoice($connection, $remoteId);
            if ($remote === null) {
                return 'aligned';
            }
            if ($push === false) {
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

        $remote = null;
        if ($link !== null) {
            $remoteId = (string) $link['qbo_id'];
            if (isset($this->invoiceReadFailed[$remoteId]) === true) {
                throw $this->invoiceReadFailed[$remoteId];
            }
            $remote = $this->invoiceById !== null
                ? ($this->invoiceById[$remoteId] ?? null)
                : $this->client->getInvoice($connection, $remoteId);
        }
        // An empty read means QuickBooks deleted this linked invoice. Do not create a replacement.
        // A failed read is stored separately and returns failed above, so it is not an empty result.
        if ($remote === null && $link !== null) {
            return 'aligned';
        }
        if ($remote === null) {
            if ($push === false) {
                $this->closeSkipped = true;

                return 'skipped';
            }
            if ($this->rejectInvoiceCurrency($payload, $ledger, $connection) === true) {
                return 'failed';
            }
            if ($reference === 'quickbooks' && $this->customTxnNumbers($connection) === true) {
                $next = $this->reservedDocNumbers !== null
                    ? ($this->reservedDocNumbers[$uuid] ?? null)
                    : $this->client->nextInvoiceDocNumber($connection);
                if (is_string($next) === false || $next === '') {
                    $this->lastError = 'quickbooks.messages.invoice-number-unconfirmed';

                    return 'failed';
                }
                $payload['DocNumber'] = $next;
                ReservedDocNumbers::remember((string) $connection['realm_id'], $uuid, $next);
            }
            if ($this->rejectLongDocNumber($payload) === true) {
                return 'failed';
            }
            $plan    = $this->invoiceTaxPlan(
                $ledger,
                $connection,
                $uuid,
                (string) $customerLink['qbo_id'],
                $itemId,
                $lineItems
            );
            $payload = $this->invoices->withTax($payload, $plan);
            $owns    = $this->fleetbaseWorksOutTax($settings);
            if ($owns === true) {
                $payload = $this->withFleetbaseTax($ledger, $connection, $uuid, $payload, $plan, []);
                if ($payload === null) {
                    return 'failed';
                }
            } elseif ($this->quickBooksTaxSendable($uuid, $payload, $connection) === false) {
                return 'failed';
            }
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
            $created = $this->client->createInvoice($connection, $payload);
            $this->rememberWrittenInvoice($created);
            $marker = $owns === true ? [self::TAX_SOURCE => self::FLEETBASE_TAX] : [];
            $ledger->putLink($marker + $this->linkFrom($connection, 'invoice', $uuid, 'Invoice', $created));
            $this->copyInvoiceNumber($ledger, $uuid, $created, $reference);
            $createdId = (string) ($created['Id'] ?? '');
            $this->recordPushedLines($ledger, $uuid, $connection, $createdId, $payload, $created, []);
            if ($copy === true && $owns === false) {
                $this->copyQuickBooksTax($ledger, $connection, $uuid, $created);
            }
            $refused = $owns === true ? $this->refusedOwnTax($ledger, $connection, $uuid, $payload, $created) : null;
            if ($refused !== null) {
                $this->lastError = $refused;

                return 'failed';
            }

            return $this->withPayment('created', $this->syncPayment($ledger, $connection, $ledger->invoices[$uuid], (string) $customerLink['qbo_id'], (string) ($created['Id'] ?? ''), $settings));
        }

        $followed = $this->followKeptCustomer($ledger, $connection, $settings, $uuid, $remote);
        if ($followed === true) {
            $companyUuid  = (string) $connection['company_uuid'];
            $realmId      = (string) $connection['realm_id'];
            $customerUuid = (string) ($ledger->invoices[$uuid]['customer_uuid'] ?? '');
            $followedLink = $ledger->link($companyUuid, $realmId, 'customer', $customerUuid);
            $remoteRef    = ['qbo_id' => $this->remoteInvoiceCustomerId($remote)];
            $customerLink = $followedLink ?? array_merge($customerLink, $remoteRef);
            $payload      = $this->invoices->withLineItems(
                $this->invoices->toQuickBooks($ledger->invoices[$uuid], (string) $customerLink['qbo_id'], $itemId),
                $lineItems
            );
            if ($reference === 'quickbooks') {
                unset($payload['DocNumber']);
            }
        }
        $leads         = $this->fleetbaseLeads($settings, 'invoice', $baseline, $remote);
        $sendsDueClear = $pushClears;
        $pushClears    = $pushClears || $leads;
        $clears        = $this->copiesClears($settings, 'invoice', $baseline, $remote);
        $customerRef   = (string) $customerLink['qbo_id'];
        $customerRef   = $this->keptCustomerRef(
            $ledger,
            $connection,
            $settings,
            $customerRef,
            $remote,
            $customerUuid,
            $link
        );
        $numberChanged = $this->copyInvoiceNumber($ledger, $uuid, $remote, $reference);
        $partyChanged  = false;
        if ($leads === false && $this->quickbooksSupplies($settings, 'invoice') === true) {
            $partyChanged = $this->copyInvoiceCustomerAndCurrency($ledger, $uuid, $remote);
        }
        $invoice     = $ledger->invoices[$uuid];
        $remoteCents = $this->majorUnits($remote['TotalAmt'] ?? 0, (string) ($invoice['currency'] ?? ''));
        $localCents  = (int) ($invoice['total'] ?? 0);
        $currencyOk  = $this->invoiceCurrencyError($ledger, $connection, (string) ($invoice['currency'] ?? '')) === null;
        // QuickBooks replaces the Fleetbase line set only when this sync copies remote lines.
        // Otherwise extra QuickBooks sales lines are not a Fleetbase mismatch.
        $remoteSuppliesLines = ($conflict === 'quickbooks' && $copy === true) || ($push === false && $copy === true);
        // When QuickBooks supplies the lines, a line Fleetbase deleted comes back instead of going.
        $removed = $remoteSuppliesLines === true ? [] : $stored['removed'];
        $mapped  = $stored['mapped'];
        $owns    = $this->ownsTax($settings, $link);
        if ($owns === true && InvoiceMapper::taxInclusive($remote) === true) {
            // Fleetbase's tax is on amounts before tax, so nothing is copied or sent.
            $this->lastError = Translation::encode('quickbooks.messages.invoice-tax-inclusive');

            return 'failed';
        }
        $ownTax  = $this->comparesOwnTax($connection, $owns);
        $matched = $this->invoiceHashesMatch(
            $invoice,
            $remote,
            $conflict,
            $remoteCents,
            $localCents,
            $reference,
            $pushClears || $clears,
            $customerRef,
            $pushClears === true && $currencyOk === true,
            $remoteSuppliesLines === false,
            [
                'removed' => $removed,
                'mapped'  => $mapped,
                // QuickBooks tax is compared only when QuickBooks supplies the lines.
                'codes'         => $remoteSuppliesLines === true ? $this->taxCodesFor($connection, $remote) : [],
                'fleetbase_tax' => $ownTax,
            ]
        );
        // A line moving to its own QuickBooks product (from the service item, or after a rename)
        // is a change to send even when the amounts and text match.
        if ($matched === true && $push === true && $remoteSuppliesLines === false) {
            $matched = $this->invoiceMapper($invoice)->movesLineItems(
                $payload,
                $remote,
                $removed,
                $mapped,
                $managed
            ) === false;
        }
        // QuickBooks did not change the invoice since the last sync, so a Fleetbase change is sent.
        // A line moving to another product is such a change too.
        $edited  = $leads === true
            && ($this->fleetbaseEditedInvoice($invoice, $remote, $reference, $customerRef, $stored, $ownTax) === true
                || ($push === true && $remoteSuppliesLines === true && $this->invoiceMapper($invoice)
                    ->movesLineItems($payload, $remote, $stored['removed'], $mapped, $managed) === true));
        $matched = $matched === true && $edited === false;
        if ($matched === true) {
            $changed = $numberChanged === true || $partyChanged === true || $followed === true;
            $outcome = $changed === true ? 'updated' : 'aligned';
            $items   = is_array($invoice['items'] ?? null) === true ? $invoice['items'] : [];
            $paired  = $this->invoiceMapper($invoice)->pairedLineIds($items, $remote, $removed, $mapped);
            $this->recordLineMap($ledger, $uuid, $connection, (string) $remote['Id'], $paired, $stored['uuids']);
            $copiesTax = $copy === true && $remoteSuppliesLines === false && $owns === false;
            if ($copiesTax === true && $this->copyQuickBooksTax($ledger, $connection, $uuid, $remote) === true) {
                $outcome = 'updated';
            }
            $supplies = $this->quickbooksSupplies($settings, 'invoice');
            if ($supplies === true && $this->copyMissingInvoiceDates($ledger, $uuid, $remote) === true) {
                $outcome = 'updated';
            }
            $invoice = $ledger->invoices[$uuid];
            $inSync  = $this->invoiceInSync($ledger, $connection, $uuid, $remote, $settings, $stored);
            $this->synced($ledger, $connection, 'invoice', $uuid, $remote, $outcome, $inSync, $baseline);
            if ($this->writeBuffer !== null) {
                return $outcome;
            }

            return $this->withPayment(
                $outcome,
                $this->syncPayment($ledger, $connection, $invoice, (string) $customerLink['qbo_id'], (string) $remote['Id'], $settings)
            );
        }

        if ($copy === true && ($conflict === 'quickbooks' || $push === false) && $edited === false) {
            $beforeStatus = (string) ($ledger->invoices[$uuid]['status'] ?? '');
            $realmId      = (string) $connection['realm_id'];
            $codes        = $this->taxCodesFor($connection, $remote);
            $applied      = $this->applyInvoiceFromRemote(
                $ledger,
                $uuid,
                $remote,
                $reference,
                $realmId,
                $clears,
                $codes,
                $owns
            );
            $changed      = $applied === true || $numberChanged === true || $partyChanged === true;
            $changed      = $changed === true || $followed === true;
            $this->applyPaymentStatus($ledger, $uuid);
            if ((string) ($ledger->invoices[$uuid]['status'] ?? '') !== $beforeStatus) {
                $changed = true;
            }
            $this->notePrimaryCopy('invoice', $uuid, $push === true && $leads === false, $applied);

            $sendNumber = $push === true && $this->fleetbaseDocNumberDiffers($ledger->invoices[$uuid], $remote, $reference) === true;
            // Fleetbase's tax wins: a different QuickBooks tax is sent back after the copy.
            $sendTax = $push === true && $ownTax === true
                && $this->ownTaxDiffers($ledger->invoices[$uuid], $remote) === true;
            if ($sendTax === true) {
                $note                               = 'quickbooks.messages.invoice-tax-from-fleetbase';
                $this->rowNotes['invoice:' . $uuid] = Translation::encode($note);
            }
            if ($sendNumber === false && $sendTax === false) {
                $inSync = $this->invoiceInSync($ledger, $connection, $uuid, $remote, $settings, $stored);
                $this->synced($ledger, $connection, 'invoice', $uuid, $remote, 'aligned', $inSync, $baseline);
                if ($this->writeBuffer !== null) {
                    return $changed === true ? 'updated' : 'aligned';
                }

                return $this->withPayment(
                    $changed === true ? 'updated' : 'aligned',
                    $this->syncPayment($ledger, $connection, $ledger->invoices[$uuid], (string) $customerLink['qbo_id'], (string) $remote['Id'], $settings)
                );
            }

            $payload = $this->invoices->withLineItems(
                $this->invoices->toQuickBooks($ledger->invoices[$uuid], $customerRef, $itemId),
                $lineItems
            );
        }

        if ($push === false) {
            return ($numberChanged === true || $partyChanged === true || $followed === true) ? 'updated' : 'aligned';
        }
        if ($edited === true) {
            // The lines Fleetbase deleted go from QuickBooks, as with Fleetbase Primary.
            $removed = $stored['removed'];
        }

        if ($pushClears === true) {
            $payload = $this->invoicePayloadWithClears(
                $ledger->invoices[$uuid],
                $customerRef,
                $itemId,
                $lineItems,
                $reference,
                $sendsDueClear
            );
        }

        if ($this->rejectInvoiceCurrency($payload, $ledger, $connection) === true) {
            return 'failed';
        }
        $this->keepFleetbaseDocNumber($payload, $ledger->invoices[$uuid], $remote, $reference);
        if ($this->rejectLongDocNumber($payload) === true) {
            return 'failed';
        }
        $refused = $owns === true ? $this->stillRefused($link, $ledger->invoices[$uuid], $remote) : null;
        if ($refused !== null) {
            $this->lastError = $refused;

            return 'failed';
        }
        // The tax plan goes on before the QuickBooks lines are paired, so a matched line and the
        // invoice keep the tax code and rate QuickBooks holds. Fleetbase's own tax goes on the
        // paired lines, so it decides their tax codes.
        $plan    = $this->invoiceTaxPlan(
            $ledger,
            $connection,
            $uuid,
            (string) $customerLink['qbo_id'],
            $itemId,
            $lineItems
        );
        $payload = $this->invoices->withTax($payload, $plan);
        $payload = $this->invoiceMapper($ledger->invoices[$uuid])
            ->withExistingLineIds($payload, $remote, $removed, $mapped, $managed);
        if ($owns === true) {
            $payload = $this->withFleetbaseTax($ledger, $connection, $uuid, $payload, $plan, $remote);
            if ($payload === null) {
                return 'failed';
            }
        } elseif ($this->quickBooksTaxSendable($uuid, $payload, $connection) === false) {
            return 'failed';
        }
        $this->rememberPushedLineIds($ledger, $uuid, $payload);

        if ($this->writeBuffer !== null) {
            $payload['Id']        = (string) $remote['Id'];
            $payload['SyncToken'] = (string) $remote['SyncToken'];
            $payload['sparse']    = true;
            $this->writeBuffer[]  = [
                'bId'       => 'invoice:' . $uuid,
                'operation' => 'update',
                'entity'    => 'Invoice',
                'payload'   => $payload,
            ];
            $this->bufferedLineMaps[$uuid] = [
                'invoice' => (string) $remote['Id'],
                'payload' => $payload,
                'cleared' => $stored['uuids'],
                'edited'  => $edited,
                'owns'    => $owns,
            ];

            return 'updated';
        }

        $updated = $this->client->updateInvoice($connection, (string) $remote['Id'], (string) $remote['SyncToken'], $payload);
        $this->rememberWrittenInvoice($updated);
        $this->putInvoiceLink($ledger, $connection, $uuid, $updated, $settings);
        $remoteId = (string) $remote['Id'];
        $this->recordPushedLines($ledger, $uuid, $connection, $remoteId, $payload, $updated, $stored['uuids']);
        if ($copy === true && $owns === false && ($remoteSuppliesLines === false || $edited === true)) {
            $this->copyQuickBooksTax($ledger, $connection, $uuid, $updated);
        }
        $refused = $owns === true ? $this->refusedOwnTax($ledger, $connection, $uuid, $payload, $updated) : null;
        if ($refused !== null) {
            $this->lastError = $refused;

            return 'failed';
        }

        return $this->withPayment('updated', $this->syncPayment($ledger, $connection, $ledger->invoices[$uuid], (string) $customerLink['qbo_id'], (string) ($updated['Id'] ?? $remote['Id']), $settings));
    }

    /**
     * An invoice currency that differs from the home currency is accepted only when
     * QuickBooks multi-currency is enabled.
     *
     * @param array<string, mixed> $connection
     */
    private function invoiceCurrencyError(SyncLedger $ledger, array $connection, string $currency): ?string
    {
        $error = $this->currencyError($ledger, $connection, $currency, 'quickbooks.messages.invoice-currency-mismatch');
        if ($error === null || $this->client->multiCurrencyEnabled($connection) === true) {
            return null;
        }

        return $error;
    }

    /**
     * @param array<string, mixed> $invoice
     * @param array<string, mixed> $remote
     */
    private function fleetbaseDocNumberDiffers(array $invoice, array $remote, string $reference): bool
    {
        if ($reference === 'quickbooks') {
            return false;
        }

        $local = trim((string) ($invoice['number'] ?? ''));
        if ($local === '') {
            return false;
        }

        return $local !== trim((string) ($remote['DocNumber'] ?? ''));
    }

    /**
     * The same DocNumber on another customer's invoice is a different invoice.
     *
     * @param array<string, mixed> $remote
     */
    private function invoiceCustomerMatches(array $remote, string $customerId): bool
    {
        $customerId = trim($customerId);
        if ($customerId === '') {
            return false;
        }

        $remoteCustomer = $this->remoteInvoiceCustomerId($remote);

        return $remoteCustomer !== '' && $remoteCustomer === $customerId;
    }

    /**
     * @param array<string, mixed> $remote
     */
    private function remoteInvoiceCustomerId(array $remote): string
    {
        $ref = $remote['CustomerRef'] ?? null;
        if (is_array($ref) === true) {
            return trim((string) ($ref['value'] ?? ''));
        }

        return trim((string) $ref);
    }

    /**
     * @param array<string, mixed> $remote
     */
    private function remoteInvoiceCurrency(array $remote): string
    {
        $ref = $remote['CurrencyRef'] ?? null;
        if (is_array($ref) === true) {
            return strtoupper(trim((string) ($ref['value'] ?? '')));
        }

        return strtoupper(trim((string) $ref));
    }

    /**
     * @param array<string, mixed> $payload
     * @param array<string, mixed> $invoice
     * @param array<string, mixed> $remote
     */
    private function keepFleetbaseDocNumber(array &$payload, array $invoice, array $remote, string $reference): void
    {
        if ($this->fleetbaseDocNumberDiffers($invoice, $remote, $reference) === false) {
            return;
        }

        $payload['DocNumber'] = trim((string) ($invoice['number'] ?? ''));
    }

    /**
     * @param array<string, mixed> $invoice
     * @param array<string, mixed> $remote
     * @param StoredLines          $lines   stored QuickBooks lines
     */
    private function invoiceHashesMatch(
        array $invoice,
        array $remote,
        string $conflict,
        int $remoteCents,
        int $localCents,
        string $reference,
        bool $pushClears,
        string $customerRef,
        bool $compareCurrency,
        bool $ignoreExtraSalesLines,
        array $lines = [],
    ): bool {
        if ($this->fleetbaseDocNumberDiffers($invoice, $remote, $reference) === true) {
            return false;
        }

        $localItems = is_array($invoice['items'] ?? null) === true ? $invoice['items'] : [];
        $compared   = $remote;
        $ownTax     = ($lines['fleetbase_tax'] ?? false) === true;
        $taxes      = $ownTax === true ? $this->ownTaxCompared($invoice, $localItems, $remote) : null;
        if ($taxes !== null && $ignoreExtraSalesLines === false) {
            // Fleetbase's tax is compared on its own, so the totals are compared before tax.
            $localCents -= $taxes['local'];
            $remoteCents -= $taxes['in_total'];
        }
        if ($ignoreExtraSalesLines === true) {
            // QuickBooks works out the tax of the lines Fleetbase sends, so only the lines are compared.
            $localCents = array_sum(array_map(
                static fn (mixed $item): int => is_array($item) === true ? (int) ($item['amount'] ?? 0) : 0,
                $localItems
            ));
            $removed     = $lines['removed'] ?? [];
            $mapped      = $lines['mapped'] ?? false;
            $limited     = $this->invoiceMapper($invoice)
                ->withoutUnmatchedSalesLines($localItems, $remote, $removed, $mapped);
            $compared    = $limited['remote'];
            $remoteCents -= $limited['omitted_cents'];
            // Fleetbase sends the lines, so a discount or the tax QuickBooks adds is not a change to
            // send: an update keeps it and the totals would differ again on every sync.
            $remoteCents -= $this->invoiceMapper($invoice)->quickbooksAdjustmentCents($remote);
        }

        $local = ['total' => $localCents];
        $other = ['total' => $remoteCents];
        foreach (['date' => 'TxnDate', 'due_date' => 'DueDate'] as $localKey => $remoteKey) {
            $value = (string) ($invoice[$localKey] ?? '');
            if ($value === '' && $pushClears === false) {
                continue;
            }
            $local[$localKey] = $value;
            $other[$localKey] = (string) ($remote[$remoteKey] ?? '');
        }
        if ($pushClears === true || array_key_exists('PrivateNote', $remote) === true) {
            $local['notes'] = InvoiceMapper::privateNote((string) ($invoice['notes'] ?? ''));
            $other['notes'] = (string) ($remote['PrivateNote'] ?? '');
        }
        if ($pushClears === true) {
            $remoteCustomer = $this->remoteInvoiceCustomerId($remote);
            if ($remoteCustomer !== '') {
                $local['customer'] = trim($customerRef);
                $other['customer'] = $remoteCustomer;
            }
        }
        if ($compareCurrency === true) {
            $localCurrency  = strtoupper(trim((string) ($invoice['currency'] ?? '')));
            $remoteCurrency = $this->remoteInvoiceCurrency($remote);
            if ($localCurrency !== '' && $remoteCurrency !== '') {
                $local['currency'] = $localCurrency;
                $other['currency'] = $remoteCurrency;
            }
        }
        $mapped         = $this->invoiceMapper($invoice)->fromQuickBooks($compared, $localItems, $lines['codes'] ?? []);
        $lineTax        = $ignoreExtraSalesLines === false && $ownTax === false;
        $local['items'] = $this->hashableInvoiceItems($localItems, $lineTax);
        $other['items'] = $this->hashableInvoiceItems($mapped['items'], $lineTax);
        if ($taxes !== null) {
            // QuickBooks and Ledger round line taxes differently, so only the invoice tax is compared.
            $local['tax'] = $taxes['local'];
            $other['tax'] = $taxes['remote'];
        } elseif ($ignoreExtraSalesLines === false) {
            $local['tax'] = (int) ($invoice['tax'] ?? 0);
            $other['tax'] = $mapped['tax'];
        }

        return ContentHash::of($local) === ContentHash::of($other);
    }

    /**
     * The update payload that sends Fleetbase clears too. QuickBooks always holds a transaction
     * date, so an empty Fleetbase date is left out rather than sent as a clear. It always holds a
     * due date too: an empty Fleetbase due date is sent only when Fleetbase is Primary
     * ($sendsDueClear). Under QuickBooks as Primary it takes the QuickBooks due date instead.
     *
     * @param array<string, mixed> $invoice
     * @param array<int, string>   $lineItems
     *
     * @return array<string, mixed>
     */
    private function invoicePayloadWithClears(
        array $invoice,
        string $customerRef,
        string $itemId,
        array $lineItems,
        string $reference,
        bool $sendsDueClear,
    ): array {
        $payload = $this->invoices->withLineItems(
            $this->invoices->toQuickBooks($invoice, $customerRef, $itemId, true),
            $lineItems
        );
        if ($reference === 'quickbooks') {
            unset($payload['DocNumber']);
        }
        if (($payload['TxnDate'] ?? null) === '') {
            unset($payload['TxnDate']);
        }
        if ($sendsDueClear === false && ($payload['DueDate'] ?? null) === '') {
            unset($payload['DueDate']);
        }

        return $payload;
    }

    /**
     * Copy the dates QuickBooks holds and Fleetbase lacks. The strict compare leaves an empty
     * Fleetbase date out when QuickBooks supplies the invoice, so a match does not copy them.
     *
     * @param array<string, mixed> $remote
     */
    private function copyMissingInvoiceDates(SyncLedger $ledger, string $uuid, array $remote): bool
    {
        $changed = false;
        foreach (['TxnDate' => 'date', 'DueDate' => 'due_date'] as $remoteKey => $local) {
            $value = trim((string) ($remote[$remoteKey] ?? ''));
            if ($value !== '' && trim((string) ($ledger->invoices[$uuid][$local] ?? '')) === '') {
                $ledger->invoices[$uuid][$local] = $value;
                $changed                         = true;
            }
        }

        return $changed;
    }

    /**
     * Whether Fleetbase changed what it sends of an invoice QuickBooks did not change since the
     * last sync: the lines and amounts, dates, notes or customer. The tax and discounts QuickBooks
     * works out on those lines are not a Fleetbase change; QuickBooks decides them, unless Fleetbase
     * works out the tax ($ownTax), whose difference is a change to send. An emptied note
     * is a change. An empty date or due date is not: QuickBooks always holds both.
     *
     * @param array<string, mixed>                                                             $invoice
     * @param array<string, mixed>                                                             $remote
     * @param array{removed: array<int, RemovedLine>, uuids: array<int, string>, mapped: bool} $stored
     */
    private function fleetbaseEditedInvoice(
        array $invoice,
        array $remote,
        string $reference,
        string $customerRef,
        array $stored,
        bool $ownTax = false,
    ): bool {
        $remoteCustomer = $this->remoteInvoiceCustomerId($remote);
        if ($remoteCustomer !== '' && $remoteCustomer !== trim($customerRef)) {
            return true;
        }
        foreach (['date' => 'TxnDate', 'due_date' => 'DueDate'] as $local => $remoteKey) {
            if (trim((string) ($invoice[$local] ?? '')) === '') {
                $invoice[$local] = (string) ($remote[$remoteKey] ?? '');
            }
        }
        $remoteCents = $this->majorUnits($remote['TotalAmt'] ?? 0, (string) ($invoice['currency'] ?? ''));
        $lines       = ['removed' => $stored['removed'], 'mapped' => $stored['mapped'], 'fleetbase_tax' => $ownTax];
        $localCents  = (int) ($invoice['total'] ?? 0);

        return $this->invoiceHashesMatch(
            $invoice,
            $remote,
            'fleetbase',
            $remoteCents,
            $localCents,
            $reference,
            true,
            $customerRef,
            false,
            true,
            $lines
        ) === false;
    }

    /**
     * The content of each line, as InvoiceMapper::item builds it. The line id, item uuid and stored
     * line are how a sync pairs a line, so they are left out. The line tax is left out too when
     * QuickBooks works it out from the lines Fleetbase sends ($withTax false).
     *
     * @param array<int, mixed> $items
     *
     * @return array<int, array<string, mixed>>
     */
    private function hashableInvoiceItems(array $items, bool $withTax = true): array
    {
        $clean = [];
        foreach ($items as $item) {
            if (is_array($item) === false) {
                continue;
            }
            $line = InvoiceMapper::item(
                (string) ($item['description'] ?? ''),
                (int) ($item['quantity'] ?? 1),
                (int) ($item['unit_price'] ?? 0),
                (int) ($item['amount'] ?? 0),
                InvoiceMapper::taxRate($item['tax_rate'] ?? 0),
                (int) ($item['tax_amount'] ?? 0)
            );
            if ($withTax === false) {
                unset($line['tax_rate'], $line['tax_amount']);
            }
            $clean[] = $line;
        }

        return $clean;
    }

    /**
     * Remember the QuickBooks ids copied onto the Fleetbase lines so the next sync
     * pairs those lines and does not treat leftover remote sales lines as local edits.
     *
     * @param array<string, mixed> $payload
     */
    private function rememberPushedLineIds(SyncLedger $ledger, string $uuid, array $payload): void
    {
        $items = $ledger->invoices[$uuid]['items'] ?? null;
        $lines = is_array($payload['Line'] ?? null) === true ? $payload['Line'] : [];
        if (is_array($items) === false) {
            return;
        }
        $sales = [];
        foreach ($lines as $line) {
            if (is_array($line) === false || (string) ($line['DetailType'] ?? '') !== 'SalesItemLineDetail') {
                continue;
            }
            $sales[] = trim((string) ($line['Id'] ?? ''));
        }
        foreach ($items as $index => $item) {
            if (is_array($item) === false || isset($sales[$index]) === false || $sales[$index] === '') {
                continue;
            }
            $ledger->invoices[$uuid]['items'][$index]['qbo_line_id'] = $sales[$index];
        }
    }

    /**
     * Copies the QuickBooks line ids stored for this invoice's link onto its items, so they pair
     * exactly. Returns the lines whose Fleetbase item was deleted (no live item holds that id),
     * the uuids of those deleted items, and whether the invoice has stored ids at all. Ids stored
     * for another company or invoice are ignored.
     *
     * @param array<string, mixed> $connection
     *
     * @return array{removed: array<int, RemovedLine>, uuids: array<int, string>, mapped: bool}
     */
    private function storedLineIds(SyncLedger $ledger, array $connection, string $uuid, bool $voided = false): array
    {
        $realm     = (string) $connection['realm_id'];
        $link      = $ledger->link((string) $connection['company_uuid'], $realm, 'invoice', $uuid);
        $invoiceId = is_array($link) === true ? trim((string) ($link['qbo_id'] ?? '')) : '';
        $items     = $ledger->invoices[$uuid]['items'] ?? null;
        if ($voided === true || $invoiceId === '' || is_array($items) === false) {
            return ['removed' => [], 'uuids' => [], 'mapped' => false];
        }
        $held = [];
        foreach ($items as $index => $item) {
            if (is_array($item) === false) {
                continue;
            }
            $id = $this->storedLineId($item['qbo_line'] ?? null, $realm, $invoiceId);
            if ($id !== '') {
                $ledger->invoices[$uuid]['items'][$index]['qbo_line_id'] = $id;
            }
            $current = trim((string) ($ledger->invoices[$uuid]['items'][$index]['qbo_line_id'] ?? ''));
            if ($current !== '') {
                $held[$current] = true;
            }
        }
        $removed = [];
        $uuids   = [];
        foreach ($this->removedLines($ledger, $uuid) as $line) {
            $id = $this->storedLineId($line, $realm, $invoiceId);
            if ($id === '' || isset($held[$id]) === true) {
                continue;
            }
            $removed[] = [
                'id'          => $id,
                'description' => (string) ($line['description'] ?? ''),
                'amount'      => (int) ($line['amount'] ?? 0),
            ];
            $uuids[]   = (string) ($line['uuid'] ?? '');
        }

        return [
            'removed' => $removed,
            'uuids'   => array_values(array_filter(array_unique($uuids))),
            'mapped'  => $held !== [] || $removed !== [],
        ];
    }

    private function storedLineId(mixed $line, string $realm, string $invoiceId): string
    {
        if (is_array($line) === false) {
            return '';
        }
        if ((string) ($line['realm'] ?? '') !== $realm || (string) ($line['invoice'] ?? '') !== $invoiceId) {
            return '';
        }

        return trim((string) ($line['id'] ?? ''));
    }

    /**
     * After QuickBooks accepted a write, each Fleetbase item keeps the line id it was sent with.
     * Items sent without one take the ids QuickBooks returned that were not sent, in order.
     *
     * @param array<string, mixed> $connection
     * @param array<string, mixed> $payload
     * @param array<int, string>   $cleared
     */
    private function recordPushedLines(
        SyncLedger $ledger,
        string $uuid,
        array $connection,
        string $invoiceId,
        array $payload,
        mixed $response,
        array $cleared,
    ): void {
        $sent  = $this->invoices->salesLineIds($payload);
        $known = array_flip(array_filter($sent, static fn (string $id): bool => $id !== ''));
        $fresh = [];
        foreach (is_array($response) === true ? $this->invoices->salesLineIds($response) : [] as $id) {
            if ($id !== '' && isset($known[$id]) === false) {
                $fresh[] = $id;
            }
        }
        $ids = [];
        foreach ($sent as $index => $id) {
            $ids[$index] = $id !== '' ? $id : (string) (array_shift($fresh) ?? '');
        }
        $this->recordLineMap($ledger, $uuid, $connection, $invoiceId, $ids, $cleared);
    }

    /**
     * QuickBooks lines replaced the Fleetbase lines, so the new items take those lines' ids.
     *
     * @param array<string, mixed> $remote
     */
    private function recordReplacedLines(SyncLedger $ledger, string $uuid, array $remote, string $realmId): void
    {
        $invoiceId = trim((string) ($remote['Id'] ?? ''));
        if ($realmId === '' || $invoiceId === '') {
            return;
        }
        $cleared = [];
        foreach ($this->removedLines($ledger, $uuid) as $line) {
            $cleared[] = is_array($line) === true ? (string) ($line['uuid'] ?? '') : '';
        }
        $ids     = $this->invoices->salesLineIds($remote);
        $cleared = array_values(array_filter($cleared));
        $this->recordLineMap($ledger, $uuid, ['realm_id' => $realmId], $invoiceId, $ids, $cleared);
    }

    /**
     * @return array<int, mixed>
     */
    private function removedLines(SyncLedger $ledger, string $uuid): array
    {
        $lines = $ledger->invoices[$uuid]['removed_lines'] ?? null;

        return is_array($lines) === true ? $lines : [];
    }

    /**
     * Marks which QuickBooks line each item is, for the directory to store. Set only once the
     * invoice matched or a write succeeded.
     *
     * @param array<string, mixed> $connection
     * @param array<int, string>   $ids        line id by item position
     * @param array<int, string>   $cleared    deleted items whose stored line is gone
     */
    private function recordLineMap(
        SyncLedger $ledger,
        string $uuid,
        array $connection,
        string $invoiceId,
        array $ids,
        array $cleared,
    ): void {
        $items = $ledger->invoices[$uuid]['items'] ?? null;
        if ($invoiceId === '' || is_array($items) === false) {
            return;
        }
        $position = 0;
        foreach ($items as $index => $item) {
            if (is_array($item) === false) {
                continue;
            }
            $id = $ids[$position] ?? '';
            $position++;
            if ($id !== '') {
                $ledger->invoices[$uuid]['items'][$index]['qbo_line_id'] = $id;
            }
        }
        $ledger->invoices[$uuid]['line_map'] = ['realm' => (string) $connection['realm_id'], 'invoice' => $invoiceId];
        if ($cleared !== []) {
            $ledger->invoices[$uuid]['cleared_lines'] = $cleared;
        }
    }

    /**
     * Whether the Fleetbase invoice and the QuickBooks invoice hold the same lines, amounts,
     * dates, note and customer. The tax and discounts QuickBooks works out are not compared.
     *
     * @param array<string, mixed>                                                             $connection
     * @param array<string, mixed>                                                             $remote
     * @param array<string, mixed>                                                             $settings
     * @param array{removed: array<int, RemovedLine>, uuids: array<int, string>, mapped: bool} $stored
     */
    private function invoiceInSync(
        SyncLedger $ledger,
        array $connection,
        string $uuid,
        array $remote,
        array $settings,
        array $stored,
    ): bool {
        $invoice = $ledger->invoices[$uuid] ?? null;
        if (is_array($invoice) === false) {
            return true;
        }
        $customer  = $ledger->link(
            (string) $connection['company_uuid'],
            (string) $connection['realm_id'],
            'customer',
            (string) ($invoice['customer_uuid'] ?? '')
        );
        $owner     = (string) ($invoice['customer_uuid'] ?? '');
        $ref       = is_array($customer) === true
            ? (string) ($customer['qbo_id'] ?? '')
            : $this->unloadedCustomerRef($ledger, $connection, $remote, $owner);
        $company   = (string) $connection['company_uuid'];
        $link      = $ledger->link($company, (string) $connection['realm_id'], 'invoice', $uuid);
        $ref       = $this->keptCustomerRef($ledger, $connection, $settings, $ref, $remote, $owner, $link);
        $reference = (string) ($settings['invoice_reference'] ?? 'fleetbase');
        $ownTax    = $this->comparesOwnTax($connection, $this->ownsTax($settings, $link));

        return $this->fleetbaseEditedInvoice($invoice, $remote, $reference, $ref, $stored, $ownTax) === false;
    }

    /**
     * The QuickBooks customer of an invoice whose Fleetbase customer link this run did not load:
     * the invoice's own QuickBooks customer when that is linked to the same Fleetbase customer
     * (an invoice that followed it, followKeptCustomer), otherwise none.
     *
     * @param array<string, mixed> $connection
     * @param array<string, mixed> $remote
     */
    private function unloadedCustomerRef(SyncLedger $ledger, array $connection, array $remote, string $owner): string
    {
        $remoteCustomer = $this->remoteInvoiceCustomerId($remote);
        if ($owner === '' || $remoteCustomer === '') {
            return '';
        }
        $local = $this->remoteInvoiceLocalCustomer($ledger, (string) $connection['realm_id'], $remoteCustomer);

        return $local === $owner ? $remoteCustomer : '';
    }

    /**
     * The QuickBooks customer an invoice is compared with and sent to. QuickBooks as Primary in
     * two-way sync keeps a customer QuickBooks moved the invoice to when Fleetbase has not linked
     * it: the Fleetbase customer stays (Activity says so), the invoice counts as in sync, and a
     * later Fleetbase edit does not move it back. A Fleetbase customer change made since is a
     * Fleetbase edit and is sent (reassignedWhileKept). Otherwise the Fleetbase customer's own.
     *
     * @param array<string, mixed>      $connection
     * @param array<string, mixed>      $settings
     * @param array<string, mixed>      $remote
     * @param array<string, mixed>|null $link       the invoice link
     */
    private function keptCustomerRef(
        SyncLedger $ledger,
        array $connection,
        array $settings,
        string $customerRef,
        array $remote,
        string $customerUuid,
        ?array $link,
    ): string {
        $remoteCustomer = $this->remoteInvoiceCustomerId($remote);
        $moved          = $remoteCustomer !== '' && $remoteCustomer !== trim($customerRef);
        if ($moved === false || $this->tokenDecides($settings, 'invoice') === false) {
            return $customerRef;
        }
        if ($this->reassignedWhileKept($link, $remote, $customerUuid) === true) {
            return $customerRef;
        }
        $local = $this->remoteInvoiceLocalCustomer($ledger, (string) $connection['realm_id'], $remoteCustomer);

        return $local === '' ? $remoteCustomer : $customerRef;
    }

    /**
     * Whether Fleetbase moved an invoice to another customer while it was kept on a QuickBooks
     * customer, and QuickBooks did not change the invoice since.
     *
     * @param array<string, mixed>|null $link   the invoice link
     * @param array<string, mixed>      $remote
     */
    private function reassignedWhileKept(?array $link, array $remote, string $customerUuid): bool
    {
        if ($link === null || QuickBooksChanges::since($link, $remote) === true) {
            return false;
        }
        $kept = trim((string) ($link[self::KEPT_CUSTOMER] ?? ''));
        $from = trim((string) ($link[self::KEPT_FROM] ?? ''));

        return $kept !== '' && $kept === $this->remoteInvoiceCustomerId($remote)
            && $from !== '' && $customerUuid !== '' && $from !== $customerUuid;
    }

    /**
     * Store an invoice link for the QuickBooks invoice as read or written, with the customer it
     * is kept on (keptInvoiceCustomer).
     *
     * @param array<string, mixed> $connection
     * @param array<string, mixed> $remote
     * @param array<string, mixed> $settings
     */
    private function putInvoiceLink(
        SyncLedger $ledger,
        array $connection,
        string $uuid,
        array $remote,
        array $settings,
        ?string $token = null,
    ): void {
        $link = $this->linkFrom($connection, 'invoice', $uuid, 'Invoice', $remote);
        if ($token !== null) {
            $link['sync_token'] = $token;
        }
        $ledger->putLink($this->keptInvoiceCustomer($ledger, $connection, $settings, $uuid, $remote) + $link);
    }

    /**
     * The QuickBooks customer an invoice is kept on (keptCustomerRef) and the Fleetbase customer
     * it kept, as invoice link columns, so that the invoice follows that QuickBooks customer once
     * Fleetbase links it (followKeptCustomer). Both null when the invoice is not kept.
     *
     * @param array<string, mixed> $connection
     * @param array<string, mixed> $settings
     * @param array<string, mixed> $remote
     *
     * @return array{kept_customer: string|null, kept_from: string|null}
     */
    private function keptInvoiceCustomer(
        SyncLedger $ledger,
        array $connection,
        array $settings,
        string $uuid,
        array $remote,
    ): array {
        $company      = (string) $connection['company_uuid'];
        $realm        = (string) $connection['realm_id'];
        $customerUuid = (string) ($ledger->invoices[$uuid]['customer_uuid'] ?? '');
        $customerLink = $ledger->link($company, $realm, 'customer', $customerUuid);
        $ref          = is_array($customerLink) === true ? trim((string) ($customerLink['qbo_id'] ?? '')) : '';
        $link         = $ledger->link($company, $realm, 'invoice', $uuid);
        $kept         = $this->keptCustomerRef($ledger, $connection, $settings, $ref, $remote, $customerUuid, $link);
        if ($kept === $ref) {
            return [self::KEPT_CUSTOMER => null, self::KEPT_FROM => null];
        }

        return [self::KEPT_CUSTOMER => $kept, self::KEPT_FROM => $customerUuid];
    }

    /**
     * QuickBooks as Primary in two-way sync: an invoice kept on a QuickBooks customer Fleetbase
     * had not linked moves to the Fleetbase customer now linked to it, so the QuickBooks record
     * wins and a later Fleetbase push does not move the invoice back. Only while QuickBooks
     * still has the invoice on that customer and Fleetbase did not move it meanwhile. True when
     * the Fleetbase invoice changed.
     *
     * @param array<string, mixed> $connection
     * @param array<string, mixed> $settings
     * @param array<string, mixed> $remote
     */
    private function followKeptCustomer(
        SyncLedger $ledger,
        array $connection,
        array $settings,
        string $uuid,
        array $remote,
    ): bool {
        $realm = (string) $connection['realm_id'];
        $link  = $ledger->link((string) $connection['company_uuid'], $realm, 'invoice', $uuid);
        if ($link === null || $this->tokenDecides($settings, 'invoice') === false) {
            return false;
        }
        $kept    = trim((string) ($link[self::KEPT_CUSTOMER] ?? ''));
        $from    = trim((string) ($link[self::KEPT_FROM] ?? ''));
        $current = (string) ($ledger->invoices[$uuid]['customer_uuid'] ?? '');
        if ($kept === '' || $kept !== $this->remoteInvoiceCustomerId($remote) || ($from !== '' && $from !== $current)) {
            return false;
        }
        $local = $this->remoteInvoiceLocalCustomer($ledger, $realm, $kept);
        if ($local === '') {
            return false;
        }
        $ledger->putLink(array_merge($link, [self::KEPT_CUSTOMER => null, self::KEPT_FROM => null]));
        if ($local === $current) {
            return false;
        }
        $ledger->invoices[$uuid]['customer_uuid'] = $local;

        return true;
    }

    /**
     * @param array<string, mixed> $remote
     */
    private function copyInvoiceNumber(SyncLedger $ledger, string $uuid, array $remote, string $reference): bool
    {
        if ($reference !== 'quickbooks') {
            return false;
        }

        $number = trim((string) ($remote['DocNumber'] ?? ''));
        if ($number === '' || $number === (string) ($ledger->invoices[$uuid]['number'] ?? '')) {
            return false;
        }

        $ledger->invoices[$uuid]['number'] = $number;
        $ledger->rememberInvoice($uuid, $ledger->invoices[$uuid]);

        return true;
    }

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
        $id = trim((string) ($existing['Id'] ?? ''));
        if (
            $id === ''
            || $this->invoiceCustomerMatches($existing, $customerId) === false
            || $ledger->remoteIdsLinked($realmId, 'Invoice', [$id], $uuid) !== []
        ) {
            return null;
        }
        $this->putMatchedLink($ledger, $connection, 'invoice', $uuid, 'Invoice', $existing);
        if ($ownsTax === true) {
            $this->markOwnTax($ledger, $connection, $uuid);
        }
        if ($this->invoiceById !== null) {
            $this->invoiceById[$id] = $existing;
        }

        return $ledger->link((string) $connection['company_uuid'], $realmId, 'invoice', $uuid);
    }

    /**
     * @param array<string, mixed>              $remote
     * @param array<string, array<int, string>> $taxCodes sales tax rates by tax code (taxCodesFor)
     * @param bool                              $ownsTax  Fleetbase works out the tax: lines keep their
     *                                                    Fleetbase rate (InvoiceMapper::withFleetbaseRates)
     */
    private function applyInvoiceFromRemote(
        SyncLedger $ledger,
        string $uuid,
        array $remote,
        string $reference,
        string $realmId = '',
        bool $clears = false,
        array $taxCodes = [],
        bool $ownsTax = false,
    ): bool {
        // Both copies run: a number change must not skip the customer and currency copy.
        $numberChanged = $this->copyInvoiceNumber($ledger, $uuid, $remote, $reference) === true;
        $partyChanged  = $this->copyInvoiceCustomerAndCurrency($ledger, $uuid, $remote) === true;
        $changed       = $numberChanged || $partyChanged;
        foreach (['TxnDate' => 'date', 'DueDate' => 'due_date'] as $remoteKey => $local) {
            $value = (string) ($remote[$remoteKey] ?? '');
            if ($value !== '' && (string) ($ledger->invoices[$uuid][$local] ?? '') !== $value) {
                $ledger->invoices[$uuid][$local] = $value;
                $changed                         = true;
            }
        }
        if ($this->copyInvoiceNote($ledger, $uuid, $remote, $clears) === true) {
            $changed = true;
        }

        $current = $ledger->invoices[$uuid]['items'] ?? [];
        $current = is_array($current) === true ? $current : [];
        $mapped  = $this->remoteInvoiceLines($ledger->invoices[$uuid], $uuid, $remote, $taxCodes, $ownsTax);
        if ($mapped['items'] !== [] && $mapped['items'] !== $this->hashableInvoiceItems($current)) {
            $ledger->invoices[$uuid]['items']                 = $this->invoiceMapper($ledger->invoices[$uuid])
                ->withLineProducts($mapped['items'], $remote, $current, $this->lineProductItems());
            $ledger->invoices[$uuid]['items_from_quickbooks'] = true;
            $changed                                          = true;
            $this->recordReplacedLines($ledger, $uuid, $remote, $realmId);
        }
        if ((int) ($ledger->invoices[$uuid]['total'] ?? 0) !== $mapped['total']) {
            $ledger->invoices[$uuid]['total'] = $mapped['total'];
            $changed                          = true;
        }
        if ((int) ($ledger->invoices[$uuid]['tax'] ?? 0) !== $mapped['tax']) {
            $ledger->invoices[$uuid]['tax'] = $mapped['tax'];
            $changed                        = true;
        }
        if ($changed === true) {
            $ledger->invoices[$uuid]['replace_from_quickbooks'] = true;
        }

        return $changed;
    }

    /**
     * The QuickBooks lines, tax and total as a copy gives them to a Fleetbase invoice
     * (InvoiceMapper::fromQuickBooks). When Fleetbase works out the tax ($ownsTax) the lines keep
     * their Fleetbase rates instead (InvoiceMapper::withFleetbaseRates), and Activity notes lines
     * only QuickBooks has, which get no Fleetbase tax.
     *
     * @param array<string, mixed>              $invoice
     * @param array<string, mixed>              $remote
     * @param array<string, array<int, string>> $taxCodes
     *
     * @return array{items: array<int, array<string, mixed>>, tax: int, total: int}
     */
    private function remoteInvoiceLines(
        array $invoice,
        string $uuid,
        array $remote,
        array $taxCodes,
        bool $ownsTax,
    ): array {
        $current = is_array($invoice['items'] ?? null) === true ? $invoice['items'] : [];
        $mapper  = $this->invoiceMapper($invoice);
        $mapped  = $mapper->fromQuickBooks($remote, $current, $taxCodes);
        if ($ownsTax === false) {
            return $mapped;
        }
        $mapped = $mapper->withFleetbaseRates($mapped, $remote, $current);
        if ($mapped['unpaired'] > 0) {
            $this->rowNotes['invoice:' . $uuid] = Translation::encode('quickbooks.messages.invoice-tax-new-line');
        }

        return $mapped;
    }

    /**
     * QuickBooks holds a longer Fleetbase note cut to its limit. That copy is not a change. An
     * empty QuickBooks note is copied only with $clears (see copiesClears).
     *
     * @param array<string, mixed> $remote
     */
    private function copyInvoiceNote(SyncLedger $ledger, string $uuid, array $remote, bool $clears): bool
    {
        $note  = (string) ($remote['PrivateNote'] ?? '');
        $local = InvoiceMapper::privateNote((string) ($ledger->invoices[$uuid]['notes'] ?? ''));
        if (($note === '' && $clears === false) || $note === $local) {
            return false;
        }
        $ledger->invoices[$uuid]['notes'] = $note;

        return true;
    }

    /**
     * @param array<string, mixed> $connection
     *
     * @return array<string, mixed>|null
     */
    private function remoteInvoice(array $connection, string $invoiceId): ?array
    {
        if ($this->blockPayments !== null || $this->invoiceById !== null) {
            $invoice = $this->invoiceById[$invoiceId] ?? null;

            return is_array($invoice) === true ? $invoice : null;
        }

        return $this->client->getInvoice($connection, $invoiceId);
    }

    /**
     * The payment link attributes, with the token this invoice synced kept beside the tokens
     * of the other invoices the payment still pays (see PaymentTokens).
     *
     * @param array<string, mixed>      $attributes
     * @param array<string, mixed>|null $legacy
     * @param array<string, mixed>      $remote     the QuickBooks payment
     *
     * @return array<string, mixed>
     */
    private function withInvoiceToken(
        SyncLedger $ledger,
        array $attributes,
        string $invoiceUuid,
        ?array $legacy,
        array $remote,
    ): array {
        $current = $ledger->link(
            (string) $attributes['company_uuid'],
            (string) $attributes['realm_id'],
            'payment',
            (string) $attributes['local_uuid']
        ) ?? $legacy;
        $token = (string) $attributes['sync_token'];
        $stale = $this->invoicesNoLongerPaid($ledger, $attributes, $current, $remote);

        return array_merge($attributes, [
            PaymentTokens::COLUMN => PaymentTokens::with($current, $invoiceUuid, $token, $stale),
        ]);
    }

    /**
     * The invoices whose token the payment link keeps although the payment no longer pays them:
     * their linked QuickBooks invoice is not in the payment's lines. An invoice whose link this
     * run did not load is kept, and so is every one when the payment lists no invoice.
     *
     * @param array<string, mixed>      $attributes
     * @param array<string, mixed>|null $current
     * @param array<string, mixed>      $remote
     *
     * @return array<int, string>
     */
    private function invoicesNoLongerPaid(SyncLedger $ledger, array $attributes, ?array $current, array $remote): array
    {
        $paid = [];
        foreach (is_array($remote['Line'] ?? null) === true ? $remote['Line'] : [] as $line) {
            foreach (is_array($line['LinkedTxn'] ?? null) === true ? $line['LinkedTxn'] : [] as $txn) {
                $invoice = is_array($txn) === true && in_array((string) ($txn['TxnType'] ?? ''), ['', 'Invoice'], true);
                if ($invoice === true) {
                    $paid[(string) ($txn['TxnId'] ?? '')] = true;
                }
            }
        }
        $company = (string) $attributes['company_uuid'];
        $realm   = (string) $attributes['realm_id'];
        $stale   = [];
        foreach (array_keys(PaymentTokens::all($current)) as $uuid) {
            $link = $ledger->link($company, $realm, 'invoice', $uuid);
            if ($paid !== [] && is_array($link) === true && isset($paid[(string) ($link['qbo_id'] ?? '')]) === false) {
                $stale[] = $uuid;
            }
        }

        return $stale;
    }

    /**
     * @param array<string, mixed>|null $remoteInvoice
     *
     * @return array<string, mixed>|null
     */
    private function cachedRemoteInvoice(?array $remoteInvoice, string $invoiceId): ?array
    {
        if ($remoteInvoice !== null || $invoiceId === '' || $this->invoiceById === null) {
            return $remoteInvoice;
        }
        $cached = $this->invoiceById[$invoiceId] ?? null;

        return is_array($cached) === true ? $cached : $remoteInvoice;
    }

    /**
     * Integer minor units applied to this invoice, across every payment.
     * One unknown amount makes the sum unknown so a partial total is not stored.
     *
     * @param array<int, array<string, mixed>> $payments
     */
    private function summedOnInvoice(array $payments, string $invoiceId, string $currency = ''): ?int
    {
        if ($payments === []) {
            return null;
        }

        $sum = 0;
        foreach ($payments as $remote) {
            $amount = $this->amountOnInvoice($remote, $invoiceId, $currency);
            if ($amount === null) {
                return null;
            }
            $sum += $amount;
        }

        return $sum;
    }

    /**
     * A payment can apply to several invoices. The line linked to this invoice
     * is the amount that belongs here. TotalAmt is only a fallback when QuickBooks
     * did not send lines.
     *
     * @param array<string, mixed> $remote
     */
    private function amountOnInvoice(array $remote, string $invoiceId, string $currency = ''): ?int
    {
        $applied = $this->linkedLineCents($remote, $invoiceId, $currency);
        if ($applied !== null) {
            return $applied;
        }
        if ($this->paymentCoversOtherInvoices($remote, $invoiceId) === true || array_key_exists('TotalAmt', $remote) === false) {
            return null;
        }

        return $this->majorUnits($remote['TotalAmt'], $currency);
    }

    /**
     * @param array<string, mixed> $remote
     */
    private function linkedLineCents(array $remote, string $invoiceId, string $currency = ''): ?int
    {
        $lines = $remote['Line'] ?? null;
        if (is_array($lines) === false || $invoiceId === '') {
            return null;
        }

        $sum   = 0;
        $found = false;
        foreach ($lines as $line) {
            if (is_array($line) === false || $this->lineLinksInvoice($line, $invoiceId) === false) {
                continue;
            }
            $found = true;
            $sum += $this->majorUnits($line['Amount'] ?? 0, $currency);
        }

        return $found === true ? $sum : null;
    }

    /**
     * @param array<string, mixed> $line
     */
    private function lineLinksInvoice(array $line, string $invoiceId): bool
    {
        $txns = $line['LinkedTxn'] ?? [];
        if (is_array($txns) === false) {
            return false;
        }

        foreach ($txns as $txn) {
            if (is_array($txn) === false) {
                continue;
            }
            $type = (string) ($txn['TxnType'] ?? '');
            if ($type !== '' && $type !== 'Invoice') {
                continue;
            }
            if ((string) ($txn['TxnId'] ?? '') === $invoiceId) {
                return true;
            }
        }

        return false;
    }

    /**
     * Ledger sets paid_at only when an invoice is paid in full (Invoice::markAsPaid), so a
     * payment read from QuickBooks that leaves money owing does not give it a paid date.
     */
    private function clearQuickBooksPaidAt(SyncLedger $ledger, string $uuid): void
    {
        if (empty($ledger->invoices[$uuid]['payment_from_quickbooks']) === false) {
            $ledger->invoices[$uuid]['paid_at'] = '';
        }
    }

    /**
     * An invoice an earlier version left part-paid because a QuickBooks discount made the amount
     * paid smaller than its total. The amount already matches, so only the status changes.
     *
     * @param array<string, mixed>|null $remoteInvoice
     */
    private function markSettledPaid(SyncLedger $ledger, string $uuid, ?array $remoteInvoice): ?string
    {
        $partial  = (string) ($ledger->invoices[$uuid]['status'] ?? '') === 'partial';
        $currency = (string) ($ledger->invoices[$uuid]['currency'] ?? '');
        if ($partial === false || $this->remoteSettled($remoteInvoice, $currency) === false) {
            return null;
        }
        $ledger->invoices[$uuid]['status']                  = 'paid';
        $ledger->invoices[$uuid]['payment_from_quickbooks'] = true;

        return 'updated';
    }

    /**
     * QuickBooks shows the invoice as fully paid: a balance of zero on a non-zero total.
     *
     * @param array<string, mixed>|null $remoteInvoice
     */
    private function remoteSettled(?array $remoteInvoice, string $currency): bool
    {
        if ($remoteInvoice === null) {
            return false;
        }
        $total   = $remoteInvoice['TotalAmt'] ?? null;
        $balance = $remoteInvoice['Balance'] ?? null;
        if ($total === null || $total === '' || $balance === null || $balance === '') {
            return false;
        }

        return $this->majorUnits($total, $currency) > 0 && $this->majorUnits($balance, $currency) === 0;
    }

    /**
     * @param array<string, mixed>             $connection
     * @param array<int, array<string, mixed>> $rows
     * @param array<string, mixed>             $settings
     * @param array<string, mixed>             $batch
     */
    private function syncInvoiceBlock(SyncLedger $ledger, array &$connection, array $rows, array $settings, array &$batch, int $now, string $trigger): bool
    {
        if ($this->entityEnabled($settings, 'invoice') === false) {
            return $this->recordDisabledRows($ledger, $connection, $rows, $batch, $trigger, true);
        }
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
        if ($this->entityPaused($settings, 'customer') === true) {
            $needed = [];
        } elseif (count($needed) === 1) {
            $only = array_key_first($needed);
            try {
                // No pending row drives this customer, so its key is the record alone. A lost create
                // is still found again by email or name, and QuickBooks refuses a second customer of
                // the same name (customers are made inactive, never deleted).
                $customerOutcome = $this->client->withWriteKey(
                    'customer:' . $only,
                    fn (): string => $this->syncCustomer($ledger, $connection, (string) $only, $settings)
                );
            } catch (QuickBooksException $exception) {
                if ($exception->isUnauthorized() === true) {
                    $this->tokenRejected($ledger, $companyUuid);
                    $batch['failed']++;

                    return true;
                }
                if ($exception->isRateLimit() === true) {
                    $this->failure($ledger, $connection, $needed[$only], $exception, $settings, $now);

                    return true;
                }
                $this->customerLookupFailed[(string) $only]  = $exception;
                $customerOutcome                             = 'failed';
            }
            if ($customerOutcome === 'failed' && isset($this->customerLookupFailed[(string) $only]) === false) {
                $this->lastError ??= 'quickbooks.messages.invoice-customer-failed';
            }
        } elseif (count($needed) > 1) {
            if ($this->syncCustomerBlock($ledger, $connection, array_values($needed), $settings, $batch, $now, $trigger, false) === true) {
                return true;
            }
        }

        $this->customersPrepared    = true;
        $this->serviceItemByCompany = [];
        $this->serviceItemErrors    = [];
        $this->serviceItemChecked   = false;
        $this->reservedDocNumbers   = null;
        $this->salesTaxByCompany    = [];
        $this->taxCustomers         = [];
        $this->taxItems             = [];
        try {
            $this->prefetchInvoices($connection, $ledger, $rows);
            $this->prefetchTaxCustomers($connection, $ledger, $rows);
            $this->prefetchPayments($connection, $ledger, $rows);
            $this->reserveInvoiceDocNumbers($ledger, $connection, $rows, $settings);

            return $this->syncBufferedBlock($ledger, $connection, $rows, $settings, $batch, $now, $trigger, true, 'invoice');
        } catch (QuickBooksException $exception) {
            if ($exception->isUnauthorized() === true) {
                $this->tokenRejected($ledger, $companyUuid);
                $batch['failed']++;

                return true;
            }
            if ($exception->isRateLimit() === true) {
                $this->failure($ledger, $connection, $rows[0], $exception, $settings, $now);
                $this->recordRow($ledger, $connection, $rows[0], 'failed', $batch, $trigger, $this->exceptionError($exception));

                return true;
            }

            throw $exception;
        } finally {
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
    }

    /**
     * Invoice writes are durable before payment reads begin. A later payment failure
     * therefore cannot leave an invoice that QuickBooks created or updated unlinked.
     *
     * @param array<string, array{row: array<string, mixed>, outcome: string, error: string|null, permanent: bool}>                                           $planned
     * @param array<string, array{ok: bool, body: array<string, mixed>, rows: array<int, array<string, mixed>>, error: string|null, status: int, halt: bool}> $results
     * @param array<string, mixed>                                                                                                                            $settings
     */
    private function persistSuccessfulInvoiceWrites(SyncLedger $ledger, array $connection, array $planned, array $results, array $settings): void
    {
        $buffered               = $this->bufferedLineMaps;
        $this->bufferedLineMaps = [];
        $this->refusedTaxRows   = [];
        foreach ($planned as $uuid => $plan) {
            $result = $results['invoice:' . $uuid] ?? null;
            if (is_array($result) === false || empty($result['ok']) === true || is_array($result['body'] ?? null) === false || ($result['body']['Id'] ?? '') === '') {
                continue;
            }

            $this->putInvoiceLink($ledger, $connection, (string) $uuid, $result['body'], $settings);
            $this->rememberWrittenInvoice($result['body']);
            $lines = $buffered[(string) $uuid] ?? null;
            if ($lines !== null) {
                $invoiceId = $lines['invoice'] !== '' ? $lines['invoice'] : (string) $result['body']['Id'];
                $this->recordPushedLines(
                    $ledger,
                    (string) $uuid,
                    $connection,
                    $invoiceId,
                    $lines['payload'],
                    $result['body'],
                    $lines['cleared']
                );
            }
            if ($plan['outcome'] === 'created') {
                $this->copyInvoiceNumber(
                    $ledger,
                    (string) $uuid,
                    $result['body'],
                    (string) ($settings['invoice_reference'] ?? 'fleetbase')
                );
            }
            $edited = ($lines['edited'] ?? false) === true;
            $owns   = ($lines['owns'] ?? false) === true;
            $copies = $edited === true || $this->copiesTaxAfterPush($settings, $plan['outcome']) === true;
            if ($owns === false && $copies === true) {
                $this->copyQuickBooksTax($ledger, $connection, (string) $uuid, $result['body']);
            }
            if ($owns === true && $lines !== null) {
                $this->markOwnTax($ledger, $connection, (string) $uuid);
                $sent    = $lines['payload'];
                $refused = $this->refusedOwnTax($ledger, $connection, (string) $uuid, $sent, $result['body']);
                if ($refused !== null) {
                    $this->refusedTaxRows[(string) $uuid] = $refused;
                }
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
        if ((string) ($settings['invoice_reference'] ?? 'fleetbase') !== 'quickbooks') {
            return;
        }
        if ($this->entityEnabled($settings, 'invoice') === false || $this->pushesRemote($this->direction($settings, 'invoice')) === false) {
            return;
        }
        if ($this->customTxnNumbers($connection) === false) {
            return;
        }

        $creates = [];
        foreach ($rows as $row) {
            $uuid = (string) ($row['local_uuid'] ?? '');
            if ($uuid !== '' && $this->invoiceCreateNeedsDocNumber($ledger, $connection, $uuid) === true) {
                $creates[] = $uuid;
            }
        }
        if (count($creates) <= 1) {
            return;
        }
        try {
            if ($this->serviceItemId($ledger, $connection) === '') {
                return;
            }
        } catch (QuickBooksException $exception) {
            if ($exception->isUnauthorized() === true || $exception->isRateLimit() === true) {
                throw $exception;
            }

            // Each create fails on its own with this error; the rest of the block still runs.
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
     * @param array<string, mixed> $connection
     */
    private function invoiceCreateNeedsDocNumber(SyncLedger $ledger, array $connection, string $uuid): bool
    {
        $invoice = $ledger->invoices[$uuid] ?? null;
        if (is_array($invoice) === false) {
            return false;
        }

        $status = (string) ($invoice['status'] ?? '');
        if (in_array($status, ['void', 'voided', 'cancelled', 'canceled', 'deleted'], true) === true || empty($invoice['deleted_at']) === false) {
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

        $number   = trim((string) ($invoice['number'] ?? ''));
        $existing = ($number !== '' && $this->invoiceByDoc !== null) ? ($this->invoiceByDoc[$number] ?? null) : null;
        if (is_array($existing) === true && $this->invoiceCustomerMatches($existing, (string) ($customerLink['qbo_id'] ?? '')) === true) {
            return false;
        }

        return true;
    }

    private function prefetchInvoices(array $connection, SyncLedger $ledger, array $rows): void
    {
        $this->invoiceById  = [];
        $this->invoiceByDoc = [];
        $ids                = [];
        $docs               = [];
        $companyUuid        = (string) $connection['company_uuid'];
        $realm              = (string) $connection['realm_id'];
        foreach ($rows as $row) {
            $uuid    = (string) ($row['local_uuid'] ?? '');
            $invoice = $ledger->invoices[$uuid] ?? null;
            $link    = $ledger->link($companyUuid, $realm, 'invoice', $uuid);
            if (is_array($link) === true && (string) ($link['qbo_id'] ?? '') !== '') {
                $ids[] = (string) $link['qbo_id'];
            } elseif (is_array($invoice) === true && trim((string) ($invoice['customer_uuid'] ?? '')) !== '' && trim((string) ($invoice['number'] ?? '')) !== '') {
                $docs[] = trim((string) $invoice['number']);
            }
        }
        $ids                        = array_values(array_unique($ids));
        $docs                       = array_values(array_unique($docs));
        $this->invoiceReadFailed    = [];
        $this->invoiceDocReadFailed = [];
        if ($ids !== []) {
            $loaded = $this->queryWhereIn(
                $connection,
                'Invoice',
                'Id',
                $ids,
                fn (string $id): ?array => $this->client->getInvoice($connection, $id)
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
        $id = trim((string) ($remote['Id'] ?? ''));
        if ($id !== '' && $this->invoiceById !== null) {
            $this->invoiceById[$id] = $remote;
        }
        $doc = trim((string) ($remote['DocNumber'] ?? ''));
        if ($doc !== '' && $this->invoiceByDoc !== null) {
            $this->invoiceByDoc[$doc] = $remote;
        }
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
        $id = trim((string) ($bill['Id'] ?? ''));
        if ($id !== '') {
            $payload['BillAddr']['Id'] = $id;
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

    /**
     * Customer and currency come back only when QuickBooks is the source.
     * A currency that is not the home currency is left alone; the other fields still copy.
     * An empty value does not clear Fleetbase.
     *
     * @param array<string, mixed> $remote
     */
    private function copyInvoiceCustomerAndCurrency(SyncLedger $ledger, string $uuid, array $remote): bool
    {
        $changed        = false;
        $company        = (string) ($ledger->invoices[$uuid]['company_uuid'] ?? '');
        $connection     = $ledger->connections[$company] ?? null;
        $realm          = is_array($connection) === true ? (string) ($connection['realm_id'] ?? '') : '';
        $remoteCustomer = $this->remoteInvoiceCustomerId($remote);
        if ($remoteCustomer !== '' && $realm !== '') {
            $localCustomer = $this->remoteInvoiceLocalCustomer($ledger, $realm, $remoteCustomer);
            if ($localCustomer === '') {
                $this->rowNotes['invoice:' . $uuid] ??= Translation::encode(
                    'quickbooks.messages.invoice-customer-not-linked',
                    ['id' => $remoteCustomer]
                );
            } elseif ($localCustomer !== (string) ($ledger->invoices[$uuid]['customer_uuid'] ?? '')) {
                $ledger->invoices[$uuid]['customer_uuid'] = $localCustomer;
                $changed                                  = true;
            }
        }
        $currency = $this->remoteInvoiceCurrency($remote);
        if ($currency !== '' && is_array($connection) === true && $this->invoiceCurrencyError($ledger, $connection, $currency) === null) {
            $current = strtoupper(trim((string) ($ledger->invoices[$uuid]['currency'] ?? '')));
            if ($current !== $currency) {
                $ledger->invoices[$uuid]['currency'] = $currency;
                $changed                             = true;
            }
        }

        return $changed;
    }

    /**
     * The Fleetbase customer linked to a QuickBooks customer. A link this run did not load is
     * read, but not added to the ledger: the row already exists and would be written again.
     */
    private function remoteInvoiceLocalCustomer(SyncLedger $ledger, string $realm, string $remoteCustomer): string
    {
        $link = $ledger->linkForRemote($realm, 'Customer', $remoteCustomer);
        if ($link === null && $ledger->customerLinkReader !== null) {
            $link = ($ledger->customerLinkReader)($realm, $remoteCustomer);
        }

        $local = is_array($link) === true ? trim((string) ($link['local_uuid'] ?? '')) : '';
        // A deleted Fleetbase customer no longer stands for its QuickBooks customer.
        if ($local !== '' && empty($ledger->customers[$local]['deleted_at']) === false) {
            return '';
        }

        return $local;
    }
}
