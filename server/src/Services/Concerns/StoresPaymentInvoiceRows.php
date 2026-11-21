<?php

namespace Fleetbase\Quickbooks\Services\Concerns;

use Fleetbase\Quickbooks\Models\Link;
use Fleetbase\Quickbooks\Services\SyncLedger;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;

/**
 * FleetbaseDirectory's payment-to-invoice rows: which invoices each QuickBooks payment applied
 * to, kept so a lost payment can settle them. Split from StoresLinks to keep each file readable.
 */
trait StoresPaymentInvoiceRows
{
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
        if (
            $local === ''
            || $qboId === ''
            || $same !== $localMatchesRemote
            || $company === ''
            || $realm === ''
            || isset($skipped[$company]) === true
        ) {
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
        $now      = Carbon::now()->toDateTimeString();
        $existing = $this->storedPaymentInvoiceLinks($rows);
        $inserts  = [];
        $updates  = [];
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
     * The payment-invoice links already stored for these rows, keyed company|realm|local uuid.
     *
     * @param array<string, array{company_uuid: string, realm_id: string, local_uuid: string, qbo_id: string}> $rows
     *
     * @return array<string, array{uuid: string, qbo_id: string}>
     */
    private function storedPaymentInvoiceLinks(array $rows): array
    {
        $existing  = [];
        $byCompany = [];
        foreach ($rows as $row) {
            $byCompany[$row['company_uuid']][] = $row['local_uuid'];
        }
        // The company filter lets the (company_uuid, local_type, local_uuid) unique index serve the lookup.
        foreach ($byCompany as $company => $localUuids) {
            foreach (array_chunk($localUuids, 200) as $chunk) {
                $found = Link::query()
                    ->where('company_uuid', (string) $company)
                    ->where('local_type', 'payment-invoice')
                    ->whereIn('local_uuid', $chunk)
                    ->get(['uuid', 'company_uuid', 'realm_id', 'local_uuid', 'qbo_id']);
                foreach ($found as $link) {
                    if (is_object($link) === false) {
                        continue;
                    }
                    $key            = (string) $link->company_uuid . '|' . (string) $link->realm_id
                        . '|' . (string) $link->local_uuid;
                    $existing[$key] = [
                        'uuid'   => (string) $link->uuid,
                        'qbo_id' => (string) $link->qbo_id,
                    ];
                }
            }
        }

        return $existing;
    }
}
