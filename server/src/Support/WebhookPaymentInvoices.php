<?php

namespace Fleetbase\Quickbooks\Support;

use Fleetbase\Ledger\Models\Invoice;
use Fleetbase\Quickbooks\Events\QuickBooksEntityChanged;
use Fleetbase\Quickbooks\Listeners\EnqueueWebhookSync;
use Fleetbase\Quickbooks\Models\Link;

/**
 * Finds the Fleetbase invoices that the payments in a webhook delivery apply to, for
 * EnqueueWebhookSync. One payment-link query, one invoice-link query and one local-invoice
 * query per delivery; a payment whose invoices can only be known from QuickBooks is read
 * there, or deferred when no reader is given.
 */
class WebhookPaymentInvoices
{
    /**
     * realm|payment id => found when QuickBooks returned that payment, missing when the read was null.
     * A throw, reauth skip, realm mismatch, or batch fault leaves the key unset.
     *
     * @var array<string, string>
     */
    public array $reads = [];

    /**
     * realm|payment id => true for a payment whose invoices can only be known by reading it
     * from QuickBooks. The webhook request leaves it to ResolveWebhookPayments.
     *
     * @var array<string, true>
     */
    public array $deferred = [];

    /**
     * Reads QuickBooks invoice ids for payments, or null to defer those payments instead.
     *
     * @see EnqueueWebhookSync::quickbooksInvoiceIdsForPayments()
     *
     * @var (\Closure(string, string, array<int, string>): array<string, array<int, string>|null>)|null
     */
    private ?\Closure $readInvoiceIds = null;

    /**
     * One payment-link query, one invoice-link query, and one local-invoice query
     * for the delivery. A link whose local_uuid is the QuickBooks payment id is not
     * the Fleetbase invoice; the invoice id comes from the payment's lines.
     *
     * @param array<int, QuickBooksEntityChanged>                                                         $events
     * @param (\Closure(string, string, array<int, string>): array<string, array<int, string>|null>)|null $readInvoiceIds null defers keyed payments
     *
     * @return array<string, array{invoice: string, invoices: array<int, string>, keyed_by_payment: bool, quickbooks_invoices: array<int, string>}>
     */
    public function resolve(string $companyUuid, array $events, bool $allowDelete, ?\Closure $readInvoiceIds): array
    {
        $this->reads          = [];
        $this->readInvoiceIds = $readInvoiceIds;
        $idsByRealm           = $this->paymentIdsByRealm($events, $allowDelete);
        if ($idsByRealm === []) {
            return [];
        }

        $byPayment                         = $this->paymentLinksByKey($companyUuid, $idsByRealm);
        [$direct, $keyed]                  = $this->directAndKeyedPayments($idsByRealm, $byPayment);
        [$remoteInvoices, $missingByRealm] = $this->remoteInvoicesForKeyed($companyUuid, $keyed, $allowDelete);
        $storedInvoice                     = $allowDelete === true ? $this->storedPaymentInvoices($companyUuid, $missingByRealm) : [];
        $linked                            = $this->invoiceLinkIndex($companyUuid, array_keys($idsByRealm), $direct, $storedInvoice, $remoteInvoices);
        $onFile                            = $this->invoicesOnFile($companyUuid, $direct, $storedInvoice, $linked['by_uuid']);

        return $this->resolvePaymentTargets($direct, $remoteInvoices, $storedInvoice, $linked, $onFile);
    }

    /**
     * @param array<int, QuickBooksEntityChanged> $events
     *
     * @return array<string, array<int, string>>
     */
    private function paymentIdsByRealm(array $events, bool $allowDelete): array
    {
        $idsByRealm = [];
        foreach ($events as $event) {
            if ($event->entityType !== 'payment' || ($allowDelete === false && $event->operation === 'delete')) {
                continue;
            }
            $idsByRealm[$event->realmId][] = $event->quickbooksId;
        }

        return $idsByRealm;
    }

    /**
     * @param array<string, array<int, string>> $idsByRealm
     *
     * @return array<string, array<int, string>>
     */
    private function paymentLinksByKey(string $companyUuid, array $idsByRealm): array
    {
        $paymentIds = [];
        foreach ($idsByRealm as $ids) {
            foreach ($ids as $paymentId) {
                $paymentIds[] = $paymentId;
            }
        }
        $paymentIds = array_values(array_unique($paymentIds));
        $byPayment  = [];
        foreach (Link::query()
            ->where('company_uuid', $companyUuid)
            ->where('qbo_entity', 'Payment')
            ->whereIn('realm_id', array_keys($idsByRealm))
            ->whereIn('qbo_id', $paymentIds)
            ->get(['realm_id', 'qbo_id', 'local_uuid']) as $link) {
            if (is_object($link) === false) {
                continue;
            }
            $key               = (string) $link->realm_id . '|' . (string) $link->qbo_id;
            $byPayment[$key][] = trim((string) $link->local_uuid);
        }

        return $byPayment;
    }

    /**
     * @param array<string, array<int, string>> $idsByRealm
     * @param array<string, array<int, string>> $byPayment
     *
     * @return array{0: array<string, array<int, string>>, 1: array<string, array<int, string>>}
     */
    private function directAndKeyedPayments(array $idsByRealm, array $byPayment): array
    {
        $direct = [];
        $keyed  = [];
        foreach ($idsByRealm as $realmId => $ids) {
            foreach (array_values(array_unique($ids)) as $paymentId) {
                $key      = $realmId . '|' . $paymentId;
                $locals   = $byPayment[$key] ?? [];
                $invoices = [];
                foreach ($locals as $uuid) {
                    if ($uuid !== '' && $uuid !== (string) $paymentId) {
                        $invoices[] = $uuid;
                    }
                }
                if ($invoices !== []) {
                    $direct[$key] = $invoices;
                } elseif ($locals !== []) {
                    $keyed[$realmId][] = (string) $paymentId;
                }
            }
        }

        return [$direct, $keyed];
    }

    /**
     * @param array<string, array<int, string>> $keyed
     *
     * @return array{0: array<string, array<int, string>>, 1: array<string, array<int, string>>}
     */
    private function remoteInvoicesForKeyed(string $companyUuid, array $keyed, bool $allowDelete): array
    {
        $remoteInvoices = [];
        $missingByRealm = [];
        foreach ($keyed as $realmId => $ids) {
            $this->readKeyedRealm($companyUuid, (string) $realmId, $ids, $allowDelete, $remoteInvoices, $missingByRealm);
        }

        return [$remoteInvoices, $missingByRealm];
    }

    /**
     * @param array<int, string>                $ids
     * @param array<string, array<int, string>> $remoteInvoices
     * @param array<string, array<int, string>> $missingByRealm
     */
    private function readKeyedRealm(string $companyUuid, string $realmId, array $ids, bool $allowDelete, array &$remoteInvoices, array &$missingByRealm): void
    {
        $ids = array_values(array_unique($ids));
        if ($this->readInvoiceIds === null) {
            foreach ($ids as $paymentId) {
                $this->deferred[$realmId . '|' . $paymentId] = true;
            }

            return;
        }

        $found = ($this->readInvoiceIds)($companyUuid, $realmId, $ids);
        foreach ($ids as $paymentId) {
            $this->rememberKeyedPayment($realmId, (string) $paymentId, $found, $allowDelete, $remoteInvoices, $missingByRealm);
        }
    }

    /**
     * @param array<string, array<int, string>|null> $found
     * @param array<string, array<int, string>>      $remoteInvoices
     * @param array<string, array<int, string>>      $missingByRealm
     */
    private function rememberKeyedPayment(string $realmId, string $paymentId, array $found, bool $allowDelete, array &$remoteInvoices, array &$missingByRealm): void
    {
        $key = $realmId . '|' . $paymentId;
        if (array_key_exists($paymentId, $found) === false) {
            return;
        }
        $invoiceIds = $found[$paymentId];
        if ($invoiceIds === null) {
            $this->reads[$key] = 'missing';
            if ($allowDelete === true) {
                $missingByRealm[$realmId][] = $paymentId;
            }

            return;
        }
        $this->reads[$key] = 'found';
        if (is_array($invoiceIds) === true && $invoiceIds !== []) {
            $remoteInvoices[$key] = $invoiceIds;
        }
    }

    /**
     * @param array<int, string>                $realmIds
     * @param array<string, array<int, string>> $direct
     * @param array<string, array<int, string>> $storedInvoice
     * @param array<string, array<int, string>> $remoteInvoices
     *
     * @return array{by_uuid: array<string, true>, by_qbo: array<string, string>}
     */
    private function invoiceLinkIndex(string $companyUuid, array $realmIds, array $direct, array $storedInvoice, array $remoteInvoices): array
    {
        $candidateUuids = $this->flattenedUuids($direct, $storedInvoice);
        $qboInvoiceIds  = $this->flattenedInvoiceIds($remoteInvoices);
        $linkedByUuid   = [];
        $uuidByQbo      = [];
        if ($candidateUuids === [] && $qboInvoiceIds === []) {
            return ['by_uuid' => $linkedByUuid, 'by_qbo' => $uuidByQbo];
        }

        $invoiceLinks = Link::query()
            ->where('company_uuid', $companyUuid)
            ->whereIn('realm_id', $realmIds)
            ->where('local_type', 'invoice')
            ->where(function ($query) use ($candidateUuids, $qboInvoiceIds): void {
                if ($candidateUuids !== []) {
                    $query->whereIn('local_uuid', $candidateUuids);
                }
                if ($qboInvoiceIds !== []) {
                    $method = $candidateUuids !== [] ? 'orWhereIn' : 'whereIn';
                    $query->{$method}('qbo_id', $qboInvoiceIds);
                }
            })
            ->get(['realm_id', 'local_uuid', 'qbo_id']);
        foreach ($invoiceLinks as $link) {
            $this->rememberInvoiceLink($link, $linkedByUuid, $uuidByQbo);
        }

        return ['by_uuid' => $linkedByUuid, 'by_qbo' => $uuidByQbo];
    }

    /**
     * @param array<string, true>   $linkedByUuid
     * @param array<string, string> $uuidByQbo
     */
    private function rememberInvoiceLink(mixed $link, array &$linkedByUuid, array &$uuidByQbo): void
    {
        if (is_object($link) === false) {
            return;
        }
        $realm = (string) $link->realm_id;
        $uuid  = trim((string) $link->local_uuid);
        $qboId = trim((string) $link->qbo_id);
        if ($uuid !== '') {
            $linkedByUuid[$realm . '|' . $uuid] = true;
        }
        if ($realm !== '' && $qboId !== '' && $uuid !== '') {
            $uuidByQbo[$realm . '|' . $qboId] = $uuid;
        }
    }

    /**
     * @param array<string, array<int, string>> $direct
     * @param array<string, array<int, string>> $storedInvoice
     *
     * @return array<int, string>
     */
    private function flattenedUuids(array $direct, array $storedInvoice): array
    {
        $candidateUuids = [];
        foreach ($direct as $uuids) {
            foreach ($uuids as $uuid) {
                $candidateUuids[] = $uuid;
            }
        }
        foreach ($storedInvoice as $uuids) {
            foreach ($uuids as $uuid) {
                $candidateUuids[] = $uuid;
            }
        }

        return array_values(array_unique($candidateUuids));
    }

    /**
     * @param array<string, array<int, string>> $remoteInvoices
     *
     * @return array<int, string>
     */
    private function flattenedInvoiceIds(array $remoteInvoices): array
    {
        $qboInvoiceIds = [];
        foreach ($remoteInvoices as $invoiceIds) {
            foreach ($invoiceIds as $invoiceId) {
                $qboInvoiceIds[] = $invoiceId;
            }
        }

        return array_values(array_unique($qboInvoiceIds));
    }

    /**
     * @param array<string, array<int, string>> $direct
     * @param array<string, array<int, string>> $storedInvoice
     * @param array<string, true>               $linkedByUuid
     *
     * @return array<string, true>
     */
    private function invoicesOnFile(string $companyUuid, array $direct, array $storedInvoice, array $linkedByUuid): array
    {
        $needFile = [];
        foreach ([$direct, $storedInvoice] as $groups) {
            foreach ($groups as $key => $uuids) {
                $realm = explode('|', $key, 2)[0];
                foreach ($uuids as $uuid) {
                    if (isset($linkedByUuid[$realm . '|' . $uuid]) === false) {
                        $needFile[] = $uuid;
                    }
                }
            }
        }
        $onFile = [];
        if ($needFile === [] || class_exists(Invoice::class) === false) {
            return $onFile;
        }
        $found = Invoice::query()
            ->where('company_uuid', $companyUuid)
            ->whereIn('uuid', array_values(array_unique($needFile)))
            ->pluck('uuid');
        foreach ($found as $uuid) {
            $onFile[(string) $uuid] = true;
        }

        return $onFile;
    }

    /**
     * @param array<string, array<int, string>>                                  $direct
     * @param array<string, array<int, string>>                                  $remoteInvoices
     * @param array<string, array<int, string>>                                  $storedInvoice
     * @param array{by_uuid: array<string, true>, by_qbo: array<string, string>} $linked
     * @param array<string, true>                                                $onFile
     *
     * @return array<string, array{invoice: string, invoices: array<int, string>, keyed_by_payment: bool, quickbooks_invoices: array<int, string>}>
     */
    private function resolvePaymentTargets(array $direct, array $remoteInvoices, array $storedInvoice, array $linked, array $onFile): array
    {
        $resolved = [];
        $this->resolveDirectTargets($direct, $linked['by_uuid'], $onFile, $resolved);
        $this->resolveRemoteTargets($remoteInvoices, $linked['by_qbo'], $resolved);
        $this->resolveStoredTargets($storedInvoice, $linked['by_uuid'], $onFile, $resolved);

        return $resolved;
    }

    /**
     * @param array<string, array<int, string>>                                                                                                    $direct
     * @param array<string, true>                                                                                                                  $linkedByUuid
     * @param array<string, true>                                                                                                                  $onFile
     * @param array<string, array{invoice: string, invoices: array<int, string>, keyed_by_payment: bool, quickbooks_invoices: array<int, string>}> $resolved
     */
    private function resolveDirectTargets(array $direct, array $linkedByUuid, array $onFile, array &$resolved): void
    {
        foreach ($direct as $key => $uuids) {
            $realm = explode('|', $key, 2)[0];
            foreach ($uuids as $uuid) {
                if (isset($linkedByUuid[$realm . '|' . $uuid]) === true || isset($onFile[$uuid]) === true) {
                    $this->addResolvedInvoice($resolved, $key, $uuid, false, []);
                }
            }
        }
    }

    /**
     * @param array<string, array<int, string>>                                                                                                    $remoteInvoices
     * @param array<string, string>                                                                                                                $uuidByQbo
     * @param array<string, array{invoice: string, invoices: array<int, string>, keyed_by_payment: bool, quickbooks_invoices: array<int, string>}> $resolved
     */
    private function resolveRemoteTargets(array $remoteInvoices, array $uuidByQbo, array &$resolved): void
    {
        foreach ($remoteInvoices as $key => $invoiceIds) {
            $realm = explode('|', $key, 2)[0];
            foreach ($invoiceIds as $invoiceId) {
                $uuid = $uuidByQbo[$realm . '|' . $invoiceId] ?? null;
                if (is_string($uuid) === false || $uuid === '') {
                    continue;
                }
                $this->addResolvedInvoice($resolved, $key, $uuid, true, $invoiceIds);
            }
        }
    }

    /**
     * @param array<string, array<int, string>>                                                                                                    $storedInvoice
     * @param array<string, true>                                                                                                                  $linkedByUuid
     * @param array<string, true>                                                                                                                  $onFile
     * @param array<string, array{invoice: string, invoices: array<int, string>, keyed_by_payment: bool, quickbooks_invoices: array<int, string>}> $resolved
     */
    private function resolveStoredTargets(array $storedInvoice, array $linkedByUuid, array $onFile, array &$resolved): void
    {
        foreach ($storedInvoice as $key => $uuids) {
            if (isset($resolved[$key]) === true) {
                continue;
            }
            $realm = explode('|', $key, 2)[0];
            foreach ($uuids as $uuid) {
                if (isset($linkedByUuid[$realm . '|' . $uuid]) === true || isset($onFile[$uuid]) === true) {
                    $this->addResolvedInvoice($resolved, $key, $uuid, true, []);
                }
            }
        }
    }

    /**
     * Every Fleetbase invoice a resolved payment applies to. The first uuid stays
     * in invoice for callers that still read that field.
     *
     * @param array<string, array{invoice: string, invoices: array<int, string>, keyed_by_payment: bool, quickbooks_invoices: array<int, string>}> $resolved
     * @param array<int, string>                                                                                                                   $quickbooksInvoices
     */
    private function addResolvedInvoice(array &$resolved, string $key, string $uuid, bool $keyedByPayment, array $quickbooksInvoices): void
    {
        if ($uuid === '') {
            return;
        }
        if (isset($resolved[$key]) === false) {
            $resolved[$key] = [
                'invoice'             => $uuid,
                'invoices'            => [],
                'keyed_by_payment'    => $keyedByPayment,
                'quickbooks_invoices' => $quickbooksInvoices,
            ];
        }
        if (in_array($uuid, $resolved[$key]['invoices'], true) === false) {
            $resolved[$key]['invoices'][] = $uuid;
        }
    }

    /**
     * Invoice uuids remembered when the payment link was stored under the payment id.
     *
     * @param array<string, array<int, string>> $idsByRealm
     *
     * @return array<string, array<int, string>> realm|payment id => invoice uuids
     */
    private function storedPaymentInvoices(string $companyUuid, array $idsByRealm): array
    {
        [$realms, $paymentIds] = $this->storedPaymentKeys($idsByRealm);
        if ($companyUuid === '' || $realms === [] || $paymentIds === []) {
            return [];
        }

        $mapped = [];
        foreach (Link::query()
            ->where('company_uuid', $companyUuid)
            ->where('local_type', 'payment-invoice')
            ->whereIn('realm_id', $realms)
            ->whereIn('local_uuid', $paymentIds)
            ->get(['realm_id', 'local_uuid', 'qbo_id']) as $link) {
            $this->rememberStoredPaymentInvoice($link, $mapped);
        }

        return $mapped;
    }

    /**
     * The distinct non-empty realms and payment ids to look up.
     *
     * @param array<string, array<int, string>> $idsByRealm
     *
     * @return array{0: array<int, string>, 1: array<int, string>}
     */
    private function storedPaymentKeys(array $idsByRealm): array
    {
        $realms     = [];
        $paymentIds = [];
        foreach ($idsByRealm as $realmId => $ids) {
            $realms[] = (string) $realmId;
            foreach ($ids as $id) {
                $paymentIds[] = (string) $id;
            }
        }
        $realms     = array_values(array_unique(array_filter($realms, static fn (string $realm): bool => $realm !== '')));
        $paymentIds = array_values(array_unique(array_filter($paymentIds, static fn (string $paymentId): bool => $paymentId !== '')));

        return [$realms, $paymentIds];
    }

    /**
     * @param array<string, array<int, string>> $mapped
     */
    private function rememberStoredPaymentInvoice(mixed $link, array &$mapped): void
    {
        if (is_object($link) === false) {
            return;
        }
        $paymentId   = trim((string) $link->local_uuid);
        $invoiceUuid = trim((string) $link->qbo_id);
        $realm       = trim((string) $link->realm_id);
        if ($realm === '' || $paymentId === '' || $invoiceUuid === '' || $invoiceUuid === $paymentId) {
            return;
        }
        $key = $realm . '|' . $paymentId;
        if (isset($mapped[$key]) === false) {
            $mapped[$key] = [];
        }
        if (in_array($invoiceUuid, $mapped[$key], true) === false) {
            $mapped[$key][] = $invoiceUuid;
        }
    }
}
