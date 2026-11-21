<?php

namespace Fleetbase\Quickbooks\Services\Concerns;

use Fleetbase\Quickbooks\Services\SyncLedger;
use Fleetbase\Quickbooks\Support\Amounts;
use Fleetbase\Quickbooks\Support\Translation;

/**
 * SyncEngine's deletes: a customer or wallet deleted in Fleetbase makes its linked QuickBooks
 * record inactive. Split from SyncsCustomers to keep each file readable.
 */
trait DeactivatesRemote
{
    /**
     * A customer or wallet deleted in Fleetbase makes its linked QuickBooks record inactive
     * (QuickBooks keeps names and accounts). Nothing is looked up or created without a link,
     * so an unrelated QuickBooks record that only shares a name or number is never touched.
     *
     * @param array<string, mixed> $connection
     */
    private function deactivateRemote(
        SyncLedger $ledger,
        array $connection,
        string $kind,
        string $uuid,
        bool $push,
    ): string {
        $link = $ledger->link((string) $connection['company_uuid'], (string) $connection['realm_id'], $kind, $uuid);
        if ($link === null || $push === false) {
            return 'aligned';
        }
        $remoteId = (string) $link['qbo_id'];
        $remote   = $kind === 'customer'
            ? $this->customerToDeactivate($ledger, $connection, $uuid, $remoteId)
            : $this->readWalletAccount($connection, $remoteId);
        if ($remote === null || (array_key_exists('Active', $remote) === true && $remote['Active'] === false)) {
            return 'aligned';
        }
        if ($kind === 'customer' && $this->keepsOpenBalance($uuid, $remote) === true) {
            return 'skipped';
        }

        return $this->writeInactive($ledger, $connection, $kind, $uuid, $remote);
    }

    /**
     * The linked QuickBooks customer of a deleted customer, or null when another live customer
     * took it over.
     *
     * @param array<string, mixed> $connection
     *
     * @return array<string, mixed>|null
     */
    private function customerToDeactivate(SyncLedger $ledger, array $connection, string $uuid, string $remoteId): ?array
    {
        if ($this->remoteTakenOver($ledger, $connection, $uuid, $remoteId) === true) {
            return null;
        }
        if (isset($this->customerLookupFailed[$uuid]) === true) {
            throw $this->customerLookupFailed[$uuid];
        }
        $cached = $this->customerRemoteCache[$uuid] ?? null;

        return is_array($cached) === true && (string) ($cached['Id'] ?? '') === $remoteId
            ? $cached
            : $this->client->getCustomer($connection, $remoteId);
    }

    /**
     * A live Fleetbase customer, of this organization or of another one on the same QuickBooks
     * company, may link the QuickBooks customer a deleted customer kept. It is not made inactive.
     *
     * @param array<string, mixed> $connection
     */
    private function remoteTakenOver(SyncLedger $ledger, array $connection, string $uuid, string $remoteId): bool
    {
        return $ledger->remoteIdsLinked((string) $connection['realm_id'], 'Customer', [$remoteId], $uuid) !== [];
    }

    /**
     * Making a customer with an open balance inactive can write that balance off in QuickBooks
     * (its own screens add a credit memo), so a deleted Fleetbase customer whose QuickBooks
     * balance is not zero stays active, and the row says why.
     *
     * @param array<string, mixed> $remote
     */
    private function keepsOpenBalance(string $uuid, array $remote): bool
    {
        $ref      = $remote['CurrencyRef'] ?? null;
        $currency = is_array($ref) === true ? (string) ($ref['value'] ?? '') : '';
        $balance  = $remote['BalanceWithJobs'] ?? $remote['Balance'] ?? '0';
        try {
            $minor = Amounts::toMinorUnits(is_int($balance) === true ? $balance : (string) $balance, $currency);
        } catch (\Throwable) {
            // An amount that cannot be read is not taken as zero: the customer stays active.
            $minor = 1;
        }
        if ($minor === 0) {
            return false;
        }
        $note = Translation::encode('quickbooks.messages.customer-inactive-open-balance', [
            'name'   => (string) ($remote['DisplayName'] ?? ''),
            'amount' => Amounts::centsToDecimal($minor, $currency),
        ]);
        $this->closeSkipped                  = true;
        $this->rowNotes['customer:' . $uuid] = $note;

        return true;
    }

    /**
     * Marks the linked QuickBooks customer or account inactive, in the batch when one is open.
     *
     * @param array<string, mixed> $connection
     * @param array<string, mixed> $remote
     */
    private function writeInactive(
        SyncLedger $ledger,
        array $connection,
        string $kind,
        string $uuid,
        array $remote,
    ): string {
        $entity          = $kind === 'customer' ? 'Customer' : 'Account';
        $payload         = ['Active' => false];
        $remoteId        = (string) $remote['Id'];
        $syncToken       = (string) $remote['SyncToken'];
        if ($this->writeBuffer !== null) {
            $this->writeBuffer[] = [
                'bId'       => $kind . ':' . $uuid,
                'operation' => 'update',
                'entity'    => $entity,
                'payload'   => $payload + ['Id' => $remoteId, 'SyncToken' => $syncToken, 'sparse' => true],
            ];

            return 'updated';
        }
        $updated = $kind === 'customer'
            ? $this->client->updateCustomer($connection, $remoteId, $syncToken, $payload)
            : $this->client->updateAccount($connection, $remoteId, $syncToken, $payload);
        $ledger->putLink($this->linkFrom($connection, $kind, $uuid, $entity, $updated));

        return 'updated';
    }
}
