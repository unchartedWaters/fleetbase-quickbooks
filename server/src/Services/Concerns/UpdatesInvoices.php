<?php

namespace Fleetbase\Quickbooks\Services\Concerns;

use Fleetbase\Quickbooks\Services\SyncEngine;
use Fleetbase\Quickbooks\Services\SyncLedger;
use Fleetbase\Quickbooks\Support\InvoiceMapper;
use Fleetbase\Quickbooks\Support\Translation;

/**
 * SyncEngine's invoice updates: compare a linked invoice with its QuickBooks invoice, then copy
 * QuickBooks or send Fleetbase. Split from SyncsInvoices to keep each file readable.
 *
 * @phpstan-import-type RemovedLine from SyncEngine
 * @phpstan-import-type InvoicePlan from SyncEngine
 * @phpstan-import-type InvoiceState from SyncEngine
 */
trait UpdatesInvoices
{
    /**
     * The invoice has a QuickBooks invoice: done when both match; otherwise copy QuickBooks or
     * send Fleetbase, by the direction and conflict rule.
     *
     * @param array<string, mixed> $connection
     * @param InvoicePlan          $plan
     * @param array<string, mixed> $remote
     */
    private function updateRemoteInvoice(
        SyncLedger $ledger,
        array $connection,
        string $uuid,
        array $plan,
        array $remote,
    ): string {
        $followed = $this->followKeptCustomer($ledger, $connection, $plan['settings'], $uuid, $remote);
        if ($followed === true) {
            $plan = $this->withFollowedCustomer($ledger, $connection, $uuid, $plan, $remote);
        }
        $state = $this->invoiceState($ledger, $connection, $uuid, $plan, $remote, $followed);
        if ($state === null) {
            return 'failed';
        }

        $matched = $this->invoiceMatches($ledger, $connection, $uuid, $plan, $remote, $state);
        if ($matched === true) {
            return $this->alignedInvoice($ledger, $connection, $uuid, $plan, $remote, $state);
        }

        $copiesRemote = $plan['conflict'] === 'quickbooks' || $plan['push'] === false;
        if ($plan['copy'] === true && $copiesRemote === true && $state['edited'] === false) {
            $outcome = $this->copyRemoteInvoice($ledger, $connection, $uuid, $plan, $remote, $state);
            if ($outcome !== null) {
                return $outcome;
            }
            $plan['payload'] = $this->invoices->withLineItems(
                $this->invoices->toQuickBooks($ledger->invoices[$uuid], $state['customerRef'], $plan['itemId']),
                $plan['lineItems']
            );
        }

        if ($plan['push'] === false) {
            return $state['changed'] === true ? 'updated' : 'aligned';
        }

        return $this->sendInvoiceUpdate($ledger, $connection, $uuid, $plan, $remote, $state);
    }

    /**
     * The plan after the invoice followed its QuickBooks customer: that customer's link and a
     * payload on it.
     *
     * @param array<string, mixed> $connection
     * @param InvoicePlan          $plan
     * @param array<string, mixed> $remote
     *
     * @return InvoicePlan
     */
    private function withFollowedCustomer(
        SyncLedger $ledger,
        array $connection,
        string $uuid,
        array $plan,
        array $remote,
    ): array {
        $companyUuid          = (string) $connection['company_uuid'];
        $realmId              = (string) $connection['realm_id'];
        $customerUuid         = (string) ($ledger->invoices[$uuid]['customer_uuid'] ?? '');
        $followedLink         = $ledger->link($companyUuid, $realmId, 'customer', $customerUuid);
        $remoteRef            = ['qbo_id' => $this->remoteInvoiceCustomerId($remote)];
        $plan['customerUuid'] = $customerUuid;
        $plan['customerLink'] = $followedLink ?? array_merge($plan['customerLink'], $remoteRef);
        $plan['payload']      = $this->plannedInvoicePayload(
            $ledger->invoices[$uuid],
            $plan['customerLink'],
            $plan['itemId'],
            $plan['lineItems'],
            $plan['reference']
        );

        return $plan;
    }

    /**
     * The invoice payload on this customer. Under QuickBooks reference QuickBooks numbers it.
     *
     * @param array<string, mixed> $invoice
     * @param array<string, mixed> $customerLink
     * @param array<int, string>   $lineItems
     *
     * @return array<string, mixed>
     */
    private function plannedInvoicePayload(
        array $invoice,
        array $customerLink,
        string $itemId,
        array $lineItems,
        string $reference,
    ): array {
        $payload = $this->invoices->withLineItems(
            $this->invoices->toQuickBooks($invoice, (string) $customerLink['qbo_id'], $itemId),
            $lineItems
        );
        if ($reference === 'quickbooks') {
            unset($payload['DocNumber']);
        }

        return $payload;
    }

    /**
     * What this sync knows before comparing: who leads, which clears go each way, the customer
     * and number QuickBooks holds, and whose tax and lines count. Null when Fleetbase works out
     * the tax of an invoice whose QuickBooks amounts include tax.
     *
     * @param array<string, mixed> $connection
     * @param InvoicePlan          $plan
     * @param array<string, mixed> $remote
     *
     * @return InvoiceState|null
     */
    private function invoiceState(
        SyncLedger $ledger,
        array $connection,
        string $uuid,
        array $plan,
        array $remote,
        bool $followed,
    ): ?array {
        $settings    = $plan['settings'];
        $leads       = $this->fleetbaseLeads($settings, 'invoice', $plan['baseline'], $remote);
        $clears      = $this->copiesClears($settings, 'invoice', $plan['baseline'], $remote);
        $customerRef = $this->keptCustomerRef(
            $ledger,
            $connection,
            $settings,
            (string) $plan['customerLink']['qbo_id'],
            $remote,
            $plan['customerUuid'],
            $plan['link']
        );
        $changed  = $this->copyInvoiceParty($ledger, $uuid, $remote, $plan, $leads) || $followed;
        $invoice  = $ledger->invoices[$uuid];
        $currency = (string) ($invoice['currency'] ?? '');
        // QuickBooks replaces the Fleetbase line set only when this sync copies remote lines.
        // Otherwise extra QuickBooks sales lines are not a Fleetbase mismatch.
        $suppliesLines = $plan['copy'] === true && ($plan['conflict'] === 'quickbooks' || $plan['push'] === false);
        $remoteCents   = $this->majorUnits($remote['TotalAmt'] ?? 0, $currency);
        $currencyOk    = $this->invoiceCurrencyError($ledger, $connection, $currency) === null;
        $owns          = $this->ownsTax($settings, $plan['link']);
        if ($owns === true && InvoiceMapper::taxInclusive($remote) === true) {
            // Fleetbase's tax is on amounts before tax, so nothing is copied or sent.
            $this->lastError = Translation::encode('quickbooks.messages.invoice-tax-inclusive');

            return null;
        }

        return [
            'leads'         => $leads,
            'pushClears'    => $plan['pushClears'] || $leads,
            'clears'        => $clears,
            'customerRef'   => $customerRef,
            'changed'       => $changed,
            'remoteCents'   => $remoteCents,
            'localCents'    => (int) ($invoice['total'] ?? 0),
            'currencyOk'    => $currencyOk,
            'suppliesLines' => $suppliesLines,
            // When QuickBooks supplies the lines, a line Fleetbase deleted comes back instead of going.
            'removed'       => $suppliesLines === true ? [] : $plan['stored']['removed'],
            'owns'          => $owns,
            'ownTax'        => $this->comparesOwnTax($connection, $owns),
            'edited'        => false,
        ];
    }

    /**
     * Copy the QuickBooks invoice number, and its customer and currency when QuickBooks supplies
     * them and leads. True when either changed.
     *
     * @param array<string, mixed> $remote
     * @param InvoicePlan          $plan
     */
    private function copyInvoiceParty(SyncLedger $ledger, string $uuid, array $remote, array $plan, bool $leads): bool
    {
        $numberChanged = $this->copyInvoiceNumber($ledger, $uuid, $remote, $plan['reference']);
        $partyChanged  = false;
        if ($leads === false && $this->quickbooksSupplies($plan['settings'], 'invoice') === true) {
            $partyChanged = $this->copyInvoiceCustomerAndCurrency($ledger, $uuid, $remote);
        }

        return $numberChanged === true || $partyChanged === true;
    }

    /**
     * Whether the invoice and its QuickBooks invoice match. Sets the state's edited flag when
     * QuickBooks did not change the invoice since the last sync and Fleetbase did, so the
     * Fleetbase change is sent.
     *
     * @param array<string, mixed> $connection
     * @param InvoicePlan          $plan
     * @param array<string, mixed> $remote
     * @param InvoiceState         $state
     */
    private function invoiceMatches(
        SyncLedger $ledger,
        array $connection,
        string $uuid,
        array $plan,
        array $remote,
        array &$state,
    ): bool {
        $invoice    = $ledger->invoices[$uuid];
        $pushClears = $state['pushClears'];
        $mapped     = $plan['stored']['mapped'];
        $matched    = $this->invoiceHashesMatch(
            $invoice,
            $remote,
            [
                'remoteCents' => $state['remoteCents'],
                'localCents'  => $state['localCents'],
                'reference'   => $plan['reference'],
                'pushClears'  => $pushClears || $state['clears'],
                'customerRef' => $state['customerRef'],
                'currency'    => $pushClears === true && $state['currencyOk'] === true,
                'linesOnly'   => $state['suppliesLines'] === false,
            ],
            [
                'removed' => $state['removed'],
                'mapped'  => $mapped,
                // QuickBooks tax is compared only when QuickBooks supplies the lines.
                'codes'         => $state['suppliesLines'] === true ? $this->taxCodesFor($connection, $remote) : [],
                'fleetbase_tax' => $state['ownTax'],
            ]
        );
        // A line moving to its own QuickBooks product (from the service item, or after a rename)
        // is a change to send even when the amounts and text match.
        if ($matched === true && $plan['push'] === true && $state['suppliesLines'] === false) {
            $matched = $this->invoiceMapper($invoice)->movesLineItems(
                $plan['payload'],
                $remote,
                $state['removed'],
                $mapped,
                $plan['managed']
            ) === false;
        }
        $state['edited'] = $state['leads'] === true && $this->fleetbaseChangedInvoice($invoice, $plan, $remote, $state);

        return $matched === true && $state['edited'] === false;
    }

    /**
     * QuickBooks did not change the invoice since the last sync, so a Fleetbase change is sent.
     * A line moving to another product is such a change too.
     *
     * @param array<string, mixed> $invoice
     * @param InvoicePlan          $plan
     * @param array<string, mixed> $remote
     * @param InvoiceState         $state
     */
    private function fleetbaseChangedInvoice(array $invoice, array $plan, array $remote, array $state): bool
    {
        $stored = $plan['stored'];
        if ($this->fleetbaseEditedInvoice(
            $invoice,
            $remote,
            $plan['reference'],
            $state['customerRef'],
            $stored,
            $state['ownTax']
        ) === true) {
            return true;
        }

        return $plan['push'] === true && $state['suppliesLines'] === true && $this->invoiceMapper($invoice)
            ->movesLineItems(
                $plan['payload'],
                $remote,
                $stored['removed'],
                $stored['mapped'],
                $plan['managed']
            ) === true;
    }

    /**
     * Both sides match: store the line pairs, copy QuickBooks tax and dates where it supplies
     * them, and sync the payment.
     *
     * @param array<string, mixed> $connection
     * @param InvoicePlan          $plan
     * @param array<string, mixed> $remote
     * @param InvoiceState         $state
     */
    private function alignedInvoice(
        SyncLedger $ledger,
        array $connection,
        string $uuid,
        array $plan,
        array $remote,
        array $state,
    ): string {
        $invoice = $ledger->invoices[$uuid];
        $outcome = $state['changed'] === true ? 'updated' : 'aligned';
        $items   = is_array($invoice['items'] ?? null) === true ? $invoice['items'] : [];
        $mapped  = $plan['stored']['mapped'];
        $paired  = $this->invoiceMapper($invoice)->pairedLineIds($items, $remote, $state['removed'], $mapped);
        $this->recordLineMap($ledger, $uuid, $connection, (string) $remote['Id'], $paired, $plan['stored']['uuids']);
        $copiesTax = $plan['copy'] === true && $state['suppliesLines'] === false && $state['owns'] === false;
        if ($copiesTax === true && $this->copyQuickBooksTax($ledger, $connection, $uuid, $remote) === true) {
            $outcome = 'updated';
        }
        $supplies = $this->quickbooksSupplies($plan['settings'], 'invoice');
        if ($supplies === true && $this->copyMissingInvoiceDates($ledger, $uuid, $remote) === true) {
            $outcome = 'updated';
        }
        $invoice = $ledger->invoices[$uuid];
        $inSync  = $this->invoiceInSync($ledger, $connection, $uuid, $remote, $plan['settings'], $plan['stored']);
        $this->synced($ledger, $connection, 'invoice', $uuid, $remote, $outcome, $inSync, $plan['baseline']);

        $remoteId = (string) $remote['Id'];

        return $this->withInvoicePayment($ledger, $connection, $uuid, $plan, $remoteId, $outcome, $invoice);
    }

    /**
     * Copy the QuickBooks invoice to Fleetbase. The outcome, or null when the Fleetbase invoice
     * number or tax must still be sent back.
     *
     * @param array<string, mixed> $connection
     * @param InvoicePlan          $plan
     * @param array<string, mixed> $remote
     * @param InvoiceState         $state
     */
    private function copyRemoteInvoice(
        SyncLedger $ledger,
        array $connection,
        string $uuid,
        array $plan,
        array $remote,
        array $state,
    ): ?string {
        $beforeStatus = (string) ($ledger->invoices[$uuid]['status'] ?? '');
        $applied      = $this->applyInvoiceFromRemote(
            $ledger,
            $uuid,
            $remote,
            $plan['reference'],
            (string) $connection['realm_id'],
            $state['clears'],
            $this->taxCodesFor($connection, $remote),
            $state['owns']
        );
        $changed = $applied === true || $state['changed'] === true;
        $this->applyPaymentStatus($ledger, $uuid);
        if ((string) ($ledger->invoices[$uuid]['status'] ?? '') !== $beforeStatus) {
            $changed = true;
        }
        $this->notePrimaryCopy('invoice', $uuid, $plan['push'] === true && $state['leads'] === false, $applied);

        $invoice    = $ledger->invoices[$uuid];
        $sendNumber = $plan['push'] === true
            && $this->fleetbaseDocNumberDiffers($invoice, $remote, $plan['reference']) === true;
        // Fleetbase's tax wins: a different QuickBooks tax is sent back after the copy.
        $sendTax = $plan['push'] === true && $state['ownTax'] === true
            && $this->ownTaxDiffers($invoice, $remote) === true;
        if ($sendTax === true) {
            $note                               = 'quickbooks.messages.invoice-tax-from-fleetbase';
            $this->rowNotes['invoice:' . $uuid] = Translation::encode($note);
        }
        if ($sendNumber === true || $sendTax === true) {
            return null;
        }
        $inSync = $this->invoiceInSync($ledger, $connection, $uuid, $remote, $plan['settings'], $plan['stored']);
        $this->synced($ledger, $connection, 'invoice', $uuid, $remote, 'aligned', $inSync, $plan['baseline']);
        $outcome = $changed === true ? 'updated' : 'aligned';

        return $this->withInvoicePayment($ledger, $connection, $uuid, $plan, (string) $remote['Id'], $outcome);
    }

    /**
     * Send the Fleetbase invoice to QuickBooks, in the batch when one is open.
     *
     * @param array<string, mixed> $connection
     * @param InvoicePlan          $plan
     * @param array<string, mixed> $remote
     * @param InvoiceState         $state
     */
    private function sendInvoiceUpdate(
        SyncLedger $ledger,
        array $connection,
        string $uuid,
        array $plan,
        array $remote,
        array $state,
    ): string {
        // The lines Fleetbase deleted go from QuickBooks, as with Fleetbase Primary.
        $removed = $state['edited'] === true ? $plan['stored']['removed'] : $state['removed'];
        $payload = $this->invoiceUpdatePayload($ledger, $connection, $uuid, $plan, $remote, $state, $removed);
        if ($payload === null) {
            return 'failed';
        }

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
                'cleared' => $plan['stored']['uuids'],
                'edited'  => $state['edited'],
                'owns'    => $state['owns'],
            ];

            return 'updated';
        }

        return $this->writeInvoiceUpdate($ledger, $connection, $uuid, $plan, $remote, $state, $payload);
    }

    /**
     * The update payload: clears, invoice number, tax plan, the QuickBooks line ids to keep, and
     * the tax of whichever side works it out. Null when it cannot be sent, with lastError saying
     * why.
     *
     * @param array<string, mixed>    $connection
     * @param InvoicePlan             $plan
     * @param array<string, mixed>    $remote
     * @param InvoiceState            $state
     * @param array<int, RemovedLine> $removed
     *
     * @return array<string, mixed>|null
     */
    private function invoiceUpdatePayload(
        SyncLedger $ledger,
        array $connection,
        string $uuid,
        array $plan,
        array $remote,
        array $state,
        array $removed,
    ): ?array {
        $payload = $plan['payload'];
        if ($state['pushClears'] === true) {
            $payload = $this->invoicePayloadWithClears(
                $ledger->invoices[$uuid],
                $state['customerRef'],
                $plan['itemId'],
                $plan['lineItems'],
                $plan['reference'],
                $plan['pushClears']
            );
        }

        if ($this->rejectInvoiceCurrency($payload, $ledger, $connection) === true) {
            return null;
        }
        $this->keepFleetbaseDocNumber($payload, $ledger->invoices[$uuid], $remote, $plan['reference']);
        if ($this->rejectLongDocNumber($payload) === true) {
            return null;
        }
        $refused = $state['owns'] === true
            ? $this->stillRefused($plan['link'], $ledger->invoices[$uuid], $remote)
            : null;
        if ($refused !== null) {
            $this->lastError = $refused;

            return null;
        }
        $owns    = $state['owns'];
        $payload = $this->taxedUpdatePayload($ledger, $connection, $uuid, $plan, $remote, $payload, $removed, $owns);
        if ($payload !== null) {
            $this->rememberPushedLineIds($ledger, $uuid, $payload);
        }

        return $payload;
    }

    /**
     * The tax plan goes on before the QuickBooks lines are paired, so a matched line and the
     * invoice keep the tax code and rate QuickBooks holds. Fleetbase's own tax goes on the
     * paired lines, so it decides their tax codes. Null when the tax cannot be sent.
     *
     * @param array<string, mixed>    $connection
     * @param InvoicePlan             $plan
     * @param array<string, mixed>    $remote
     * @param array<string, mixed>    $payload
     * @param array<int, RemovedLine> $removed
     *
     * @return array<string, mixed>|null
     */
    private function taxedUpdatePayload(
        SyncLedger $ledger,
        array $connection,
        string $uuid,
        array $plan,
        array $remote,
        array $payload,
        array $removed,
        bool $owns,
    ): ?array {
        $customerId = (string) $plan['customerLink']['qbo_id'];
        $taxPlan    = $this->invoiceTaxPlan(
            $ledger,
            $connection,
            $uuid,
            $customerId,
            $plan['itemId'],
            $plan['lineItems']
        );
        $payload = $this->invoices->withTax($payload, $taxPlan);
        $payload = $this->invoiceMapper($ledger->invoices[$uuid])
            ->withExistingLineIds($payload, $remote, $removed, $plan['stored']['mapped'], $plan['managed']);
        if ($owns === true) {
            return $this->withFleetbaseTax($ledger, $connection, $uuid, $payload, $taxPlan, $remote);
        }

        return $this->quickBooksTaxSendable($uuid, $payload, $connection) === true ? $payload : null;
    }

    /**
     * @param array<string, mixed> $connection
     * @param InvoicePlan          $plan
     * @param array<string, mixed> $remote
     * @param InvoiceState         $state
     * @param array<string, mixed> $payload
     */
    private function writeInvoiceUpdate(
        SyncLedger $ledger,
        array $connection,
        string $uuid,
        array $plan,
        array $remote,
        array $state,
        array $payload,
    ): string {
        $remoteId = (string) $remote['Id'];
        $updated  = $this->client->updateInvoice($connection, $remoteId, (string) $remote['SyncToken'], $payload);
        $this->rememberWrittenInvoice($updated);
        $this->putInvoiceLink($ledger, $connection, $uuid, $updated, $plan['settings']);
        $this->recordPushedLines($ledger, $uuid, $connection, $remoteId, $payload, $updated, $plan['stored']['uuids']);
        $copiesTax = $state['suppliesLines'] === false || $state['edited'] === true;
        if ($plan['copy'] === true && $state['owns'] === false && $copiesTax === true) {
            $this->copyQuickBooksTax($ledger, $connection, $uuid, $updated);
        }
        $refused = $state['owns'] === true
            ? $this->refusedOwnTax($ledger, $connection, $uuid, $payload, $updated)
            : null;
        if ($refused !== null) {
            $this->lastError = $refused;

            return 'failed';
        }
        $updatedId = (string) ($updated['Id'] ?? $remote['Id']);

        return $this->withInvoicePayment($ledger, $connection, $uuid, $plan, $updatedId, 'updated');
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
}
