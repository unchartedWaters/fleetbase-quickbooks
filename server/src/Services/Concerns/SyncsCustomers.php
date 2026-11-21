<?php

namespace Fleetbase\Quickbooks\Services\Concerns;

use Fleetbase\Quickbooks\Services\QuickBooksClient;
use Fleetbase\Quickbooks\Services\SyncLedger;
use Fleetbase\Quickbooks\Support\Amounts;
use Fleetbase\Quickbooks\Support\ContentHash;
use Fleetbase\Quickbooks\Support\CustomerMapper;
use Fleetbase\Quickbooks\Support\QuickBooksChanges;
use Fleetbase\Quickbooks\Support\QuickBooksException;
use Fleetbase\Quickbooks\Support\Translation;

/**
 * SyncEngine's customer sync: sending Fleetbase customers to QuickBooks, matching and adopting
 * QuickBooks customers, copying QuickBooks changes back, and making deleted customers inactive.
 * It shares the engine's state, so it lives here only to keep SyncEngine.php readable.
 */
trait SyncsCustomers
{
    /**
     * @param array<string, mixed> $connection
     * @param array<string, mixed> $settings
     */
    private function syncCustomer(SyncLedger $ledger, array $connection, string $uuid, array $settings): string
    {
        $direction = $this->direction($settings, 'customer');
        if ($this->entityPaused($settings, 'customer') === true) {
            $this->closeSkipped = true;

            return 'skipped';
        }

        $customer = $ledger->customers[$uuid] ?? null;
        if ($customer === null) {
            $this->lastError = 'quickbooks.messages.customer-missing';

            return 'failed';
        }
        if (empty($customer['deleted_at']) === false) {
            return $this->deactivateRemote($ledger, $connection, 'customer', $uuid, $this->pushesRemote($direction));
        }

        $copy       = $this->copiesRemote($direction);
        $push       = $this->pushesRemote($direction);
        $reference  = (string) ($settings['customer_reference'] ?? 'fleetbase');
        $conflict   = (string) ($settings['customer_conflict'] ?? 'fleetbase');
        $pushClears = $this->sendsClears($conflict, $push, $copy);
        $payload    = $this->customers->toQuickBooks($this->customers->fromParty($customer));
        $email      = trim((string) ($customer['email'] ?? ''));
        $link       = $ledger->link((string) $connection['company_uuid'], (string) $connection['realm_id'], 'customer', $uuid);
        if ($link !== null && $link['realm_id'] !== $connection['realm_id']) {
            $link = null;
        }
        $baseline = $link;
        if (isset($this->customerLookupFailed[$uuid]) === true) {
            throw $this->customerLookupFailed[$uuid];
        }

        $remote    = null;
        $cacheHit  = $this->customerRemoteCache !== null && array_key_exists($uuid, $this->customerRemoteCache) === true;
        if ($cacheHit === true && is_array($this->customerRemoteCache[$uuid]) === true) {
            $cached = $this->customerRemoteCache[$uuid];
            $remote = $this->unclaimedRemoteCustomer($ledger, $connection, $uuid, $cached, $link);
            $link   = $this->adoptRemoteCustomer($ledger, $connection, $uuid, $remote, $link);
        } elseif ($cacheHit === false && $link === null) {
            $name   = (string) ($payload['DisplayName'] ?? '');
            $remote = $this->findRemoteCustomer($ledger, $connection, $uuid, $email, $name);
            if ($remote !== null) {
                $this->putMatchedLink($ledger, $connection, 'customer', $uuid, 'Customer', $remote);
                if ($reference === 'quickbooks' && $copy === true) {
                    $before = $ledger->customers[$uuid];
                    $this->applyCustomerFromRemote($ledger, $uuid, $remote);
                    $inSync = $this->customerInSync($ledger, $uuid, $remote);
                    $token  = $this->tokenAfterSync(null, $remote, $inSync, 'customer', $settings);
                    $first  = $this->linkFrom($connection, 'customer', $uuid, 'Customer', $remote);
                    $ledger->putLink(['sync_token' => $token] + $first);
                    $this->followUpFirstLink($ledger, $connection, 'customer', $uuid, $token);

                    return $ledger->customers[$uuid] !== $before ? 'updated' : 'aligned';
                }
            }
        } elseif ($cacheHit === false && $link !== null) {
            $remote = $this->client->getCustomer($connection, (string) $link['qbo_id']);
        }
        if ($remote === null && $link !== null) {
            return 'aligned';
        }
        if ($remote === null) {
            if ($this->lastError !== null) {
                return 'failed';
            }
            if ($push === false) {
                $this->closeSkipped = true;

                return 'skipped';
            }
            $payload = $this->withCreateCurrency($ledger, $connection, $uuid, $payload);
            if ($this->writeBuffer !== null) {
                $this->writeBuffer[] = [
                    'bId'       => 'customer:' . $uuid,
                    'operation' => 'create',
                    'entity'    => 'Customer',
                    'payload'   => $payload,
                ];

                return 'created';
            }
            $created = $this->client->createCustomer($connection, $payload);
            $ledger->putLink($this->linkFrom($connection, 'customer', $uuid, 'Customer', $created));

            return 'created';
        }

        // QuickBooks did not change the customer since the last sync: Fleetbase leads, clears included.
        if ($pushClears === false && $this->fleetbaseLeads($settings, 'customer', $baseline, $remote) === true) {
            $pushClears = true;
        }
        if ($pushClears === true) {
            $payload = $this->customers->toQuickBooks($this->customers->fromParty($ledger->customers[$uuid]), true);
        }

        if ($this->customerMatches($payload, $remote) === true) {
            $copied = $copy === true && $pushClears === false
                && $this->copyCustomerNotesAndAddress($ledger, $uuid, $remote) === true;
            $inSync = $this->customerInSync($ledger, $uuid, $remote);

            return $this->syncedIf($ledger, $connection, 'customer', $uuid, $remote, $copied, $inSync, $baseline);
        }

        if ($reference === 'quickbooks' && $copy === true) {
            $this->copyCustomerName($ledger, $uuid, $remote);
            $payload = $this->customers->toQuickBooks($this->customers->fromParty($ledger->customers[$uuid]), $pushClears);
        }

        if ($this->customerMatches($payload, $remote) === true) {
            $inSync = $this->customerInSync($ledger, $uuid, $remote);

            return $this->synced($ledger, $connection, 'customer', $uuid, $remote, 'updated', $inSync, $baseline);
        }

        if ($push === false && $copy === true) {
            $before = $ledger->customers[$uuid];
            $this->applyCustomerFromRemote($ledger, $uuid, $remote);
            $changed = $ledger->customers[$uuid] !== $before;
            $inSync  = $this->customerInSync($ledger, $uuid, $remote);

            return $this->syncedIf($ledger, $connection, 'customer', $uuid, $remote, $changed, $inSync, $baseline);
        }

        // QuickBooks wins only a record it changed since the last sync; otherwise Fleetbase is sent.
        if ($conflict === 'quickbooks' && $copy === true && QuickBooksChanges::since($baseline, $remote) === true) {
            $before = $ledger->customers[$uuid];
            $clears = $this->copiesClears($settings, 'customer', $baseline, $remote);
            $this->applyCustomerFromRemote($ledger, $uuid, $remote, $clears);
            $changed = $ledger->customers[$uuid] !== $before;
            $this->notePrimaryCopy('customer', $uuid, true, $changed);
            $inSync = $this->customerInSync($ledger, $uuid, $remote);

            return $this->syncedIf($ledger, $connection, 'customer', $uuid, $remote, $changed, $inSync, $baseline);
        }

        if ($reference === 'quickbooks') {
            $payload['DisplayName'] = (string) ($remote['DisplayName'] ?? $payload['DisplayName'] ?? '');
        }

        if ($push === false) {
            return 'aligned';
        }

        $this->keepBillAddrId($payload, $remote);
        if ($this->writeBuffer !== null) {
            $payload['Id']        = (string) $remote['Id'];
            $payload['SyncToken'] = (string) $remote['SyncToken'];
            $payload['sparse']    = true;
            $this->writeBuffer[]  = [
                'bId'       => 'customer:' . $uuid,
                'operation' => 'update',
                'entity'    => 'Customer',
                'payload'   => $payload,
            ];

            return 'updated';
        }

        $updated = $this->client->updateCustomer($connection, (string) $remote['Id'], (string) $remote['SyncToken'], $payload);
        $ledger->putLink($this->linkFrom($connection, 'customer', $uuid, 'Customer', $updated));

        return 'updated';
    }

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
        if ($kind === 'customer') {
            if ($this->remoteTakenOver($ledger, $connection, $uuid, $remoteId) === true) {
                return 'aligned';
            }
            if (isset($this->customerLookupFailed[$uuid]) === true) {
                throw $this->customerLookupFailed[$uuid];
            }
            $cached = $this->customerRemoteCache[$uuid] ?? null;
            $remote = is_array($cached) === true && (string) ($cached['Id'] ?? '') === $remoteId
                ? $cached
                : $this->client->getCustomer($connection, $remoteId);
        } else {
            if (isset($this->accountReadFailed[$remoteId]) === true) {
                throw $this->accountReadFailed[$remoteId];
            }
            $remote = $this->accountById !== null
                ? ($this->accountById[$remoteId] ?? null)
                : $this->client->getAccount($connection, $remoteId);
        }
        if ($remote === null || (array_key_exists('Active', $remote) === true && $remote['Active'] === false)) {
            return 'aligned';
        }
        if ($kind === 'customer' && $this->keepsOpenBalance($uuid, $remote) === true) {
            return 'skipped';
        }

        return $this->writeInactive($ledger, $connection, $kind, $uuid, $remote);
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
        $entity    = $kind === 'customer' ? 'Customer' : 'Account';
        $payload   = ['Active' => false];
        $id        = (string) $remote['Id'];
        $syncToken = (string) $remote['SyncToken'];
        if ($this->writeBuffer !== null) {
            $this->writeBuffer[] = [
                'bId'       => $kind . ':' . $uuid,
                'operation' => 'update',
                'entity'    => $entity,
                'payload'   => $payload + ['Id' => $id, 'SyncToken' => $syncToken, 'sparse' => true],
            ];

            return 'updated';
        }
        $updated = $kind === 'customer'
            ? $this->client->updateCustomer($connection, $id, $syncToken, $payload)
            : $this->client->updateAccount($connection, $id, $syncToken, $payload);
        $ledger->putLink($this->linkFrom($connection, $kind, $uuid, $entity, $updated));

        return 'updated';
    }

    /**
     * @param array<string, mixed> $payload
     * @param array<string, mixed> $remote
     */
    private function customerMatches(array $payload, array $remote): bool
    {
        $local = [
            'email' => $this->comparableEmail((string) ($payload['PrimaryEmailAddr']['Address'] ?? '')),
            'name'  => CustomerMapper::displayName((string) ($payload['DisplayName'] ?? '')),
            'phone' => $this->comparablePhone((string) ($payload['PrimaryPhone']['FreeFormNumber'] ?? '')),
        ];
        if (array_key_exists('Notes', $payload) === true) {
            $local['notes'] = (string) $payload['Notes'];
        }
        if (isset($payload['BillAddr']) === true && is_array($payload['BillAddr']) === true) {
            $local['address'] = [
                'city'        => (string) ($payload['BillAddr']['City'] ?? ''),
                'country'     => (string) ($payload['BillAddr']['Country'] ?? ''),
                'line1'       => (string) ($payload['BillAddr']['Line1'] ?? ''),
                'line2'       => (string) ($payload['BillAddr']['Line2'] ?? ''),
                'postal_code' => (string) ($payload['BillAddr']['PostalCode'] ?? ''),
                'state'       => (string) ($payload['BillAddr']['CountrySubDivisionCode'] ?? ''),
            ];
        }

        $other = [
            'email' => $this->comparableEmail((string) ($remote['PrimaryEmailAddr']['Address'] ?? '')),
            'name'  => CustomerMapper::displayName((string) ($remote['DisplayName'] ?? '')),
            'phone' => $this->comparablePhone((string) ($remote['PrimaryPhone']['FreeFormNumber'] ?? '')),
        ];
        if (array_key_exists('notes', $local) === true) {
            $other['notes'] = (string) ($remote['Notes'] ?? '');
        }
        if (array_key_exists('address', $local) === true) {
            $bill             = is_array($remote['BillAddr'] ?? null) === true ? $remote['BillAddr'] : [];
            $other['address'] = [
                'city'        => (string) ($bill['City'] ?? ''),
                'country'     => (string) ($bill['Country'] ?? ''),
                'line1'       => (string) ($bill['Line1'] ?? ''),
                'line2'       => (string) ($bill['Line2'] ?? ''),
                'postal_code' => (string) ($bill['PostalCode'] ?? ''),
                'state'       => (string) ($bill['CountrySubDivisionCode'] ?? ''),
            ];
        }

        return ContentHash::of($local) === ContentHash::of($other);
    }

    /**
     * Same key import matching uses: trimmed, lower-case email.
     */
    private function comparableEmail(string $email): string
    {
        return strtolower(trim($email));
    }

    /**
     * Same key import matching uses: digits only, so punctuation is not a change.
     */
    private function comparablePhone(string $phone): string
    {
        return preg_replace('/\D+/', '', $phone) ?? '';
    }

    /**
     * Whether the Fleetbase customer and the QuickBooks customer hold the same values, cleared
     * fields included.
     *
     * @param array<string, mixed> $remote
     */
    private function customerInSync(SyncLedger $ledger, string $uuid, array $remote): bool
    {
        $customer = $ledger->customers[$uuid] ?? null;
        if (is_array($customer) === false) {
            return true;
        }

        $payload = $this->customers->toQuickBooks($this->customers->fromParty($customer), true);

        return $this->customerMatches($payload, $remote);
    }

    /**
     * Copy the QuickBooks name onto the Fleetbase customer. A name that only differs by what
     * QuickBooks cannot store (a colon sent as a hyphen, a line break as a space) is the
     * same name and is kept, so the echo of Fleetbase's own push does not rewrite it.
     *
     * @param array<string, mixed> $remote
     */
    private function copyCustomerName(SyncLedger $ledger, string $uuid, array $remote): void
    {
        $name  = trim((string) ($remote['DisplayName'] ?? ''));
        $local = (string) ($ledger->customers[$uuid]['name'] ?? '');
        if ($name !== '' && CustomerMapper::displayName($local) !== CustomerMapper::displayName($name)) {
            $ledger->customers[$uuid]['name'] = $name;
        }
    }

    /**
     * @param array<string, mixed> $remote
     * @param bool                 $clears also empty what QuickBooks holds empty (see copiesClears)
     */
    private function applyCustomerFromRemote(
        SyncLedger $ledger,
        string $uuid,
        array $remote,
        bool $clears = false,
    ): void {
        $this->copyCustomerName($ledger, $uuid, $remote);
        $email = (string) ($remote['PrimaryEmailAddr']['Address'] ?? '');
        if ($email !== '') {
            $ledger->customers[$uuid]['email'] = $email;
        }
        $phone = (string) ($remote['PrimaryPhone']['FreeFormNumber'] ?? '');
        if ($phone !== '') {
            $ledger->customers[$uuid]['phone'] = $phone;
        }
        if ($clears === true) {
            $this->copyCustomerClears($ledger, $uuid, $remote);
        }
        $this->copyCustomerNotesAndAddress($ledger, $uuid, $remote);
        $ledger->touchCustomer($uuid);
    }

    /**
     * Empty the Fleetbase email, phone, notes and billing address that QuickBooks holds empty.
     * QuickBooks leaves an empty field out of a record it returns, so a missing field is empty.
     *
     * @param array<string, mixed> $remote
     */
    private function copyCustomerClears(SyncLedger $ledger, string $uuid, array $remote): void
    {
        $customer = $ledger->customers[$uuid];
        $emptied  = [
            'email' => trim((string) ($remote['PrimaryEmailAddr']['Address'] ?? '')) === '',
            'phone' => trim((string) ($remote['PrimaryPhone']['FreeFormNumber'] ?? '')) === '',
            'notes' => (string) ($remote['Notes'] ?? '') === '',
        ];
        foreach ($emptied as $field => $empty) {
            if ($empty === true && trim((string) ($customer[$field] ?? '')) !== '') {
                $ledger->customers[$uuid][$field] = null;
            }
        }
        $bill    = is_array($remote['BillAddr'] ?? null) === true ? $remote['BillAddr'] : [];
        $address = is_array($customer['address'] ?? null) === true ? $customer['address'] : [];
        if ($this->customers->addressFromBillAddr($bill) === null && $this->customers->hasAddress($address) === true) {
            $ledger->customers[$uuid]['address'] = $this->customers->addressFromBillAddr($bill, true);
        }
    }

    /**
     * Notes and billing address are omitted from the outbound payload when Fleetbase
     * has none and QuickBooks is the source, so a match on name, email, and phone
     * still has to copy them. An empty QuickBooks note or address is not copied,
     * the same as email and phone. A Fleetbase-primary push sends the clear instead.
     * A longer Fleetbase note that QuickBooks holds cut to its limit is kept in full.
     *
     * @param array<string, mixed> $remote
     */
    private function copyCustomerNotesAndAddress(SyncLedger $ledger, string $uuid, array $remote): bool
    {
        $before = $ledger->customers[$uuid];
        $notes  = (string) ($remote['Notes'] ?? '');
        $sent   = mb_substr((string) ($before['notes'] ?? ''), 0, CustomerMapper::NOTES_MAX_LENGTH);
        if ($notes !== '' && $notes !== $sent) {
            $ledger->customers[$uuid]['notes'] = $notes;
        }
        $bill = $remote['BillAddr'] ?? null;
        if (is_array($bill) === true) {
            $address = $this->customers->addressFromBillAddr($bill);
            if ($address !== null) {
                $ledger->customers[$uuid]['address'] = $address;
            }
        }
        if ($ledger->customers[$uuid] === $before) {
            return false;
        }

        $ledger->touchCustomer($uuid);

        return true;
    }

    /**
     * The QuickBooks customer a lookup found, or null when another Fleetbase customer, of this
     * organization or of another one on the same QuickBooks company, already syncs with it.
     * The customer's own link always counts as its own.
     *
     * @param array<string, mixed>      $connection
     * @param array<string, mixed>|null $remote
     * @param array<string, mixed>|null $link
     *
     * @return array<string, mixed>|null
     */
    private function unclaimedRemoteCustomer(
        SyncLedger $ledger,
        array $connection,
        string $uuid,
        ?array $remote,
        ?array $link,
    ): ?array {
        $remoteId = (string) ($remote['Id'] ?? '');
        if ($remote === null || $remoteId === '' || $remoteId === (string) ($link['qbo_id'] ?? '')) {
            return $remote;
        }
        $taken = $ledger->remoteIdsLinked((string) $connection['realm_id'], 'Customer', [$remoteId], $uuid);

        return $taken === [] ? $remote : null;
    }

    /**
     * Link the customer to the QuickBooks customer the block lookup found, and return its link.
     * A link to that customer already stays as it is, its token included: a sync that fails
     * later keeps the baseline it had.
     *
     * @param array<string, mixed>      $connection
     * @param array<string, mixed>|null $remote
     * @param array<string, mixed>|null $link
     *
     * @return array<string, mixed>|null
     */
    private function adoptRemoteCustomer(
        SyncLedger $ledger,
        array $connection,
        string $uuid,
        ?array $remote,
        ?array $link,
    ): ?array {
        if ($remote === null || ($link !== null && (string) $link['qbo_id'] === (string) ($remote['Id'] ?? ''))) {
            return $link;
        }
        $this->putMatchedLink($ledger, $connection, 'customer', $uuid, 'Customer', $remote);

        return $ledger->link((string) $connection['company_uuid'], (string) $connection['realm_id'], 'customer', $uuid);
    }

    /**
     * The QuickBooks customer with this email, or else with this display name whatever its
     * email. A phone number alone never matches. A match another Fleetbase customer already
     * syncs with is passed over, so a taken email match does not hide a free name match.
     *
     * @param array<string, mixed> $connection
     *
     * @return array<string, mixed>|null
     */
    private function findRemoteCustomer(
        SyncLedger $ledger,
        array $connection,
        string $uuid,
        string $email,
        string $displayName,
    ): ?array {
        $email = trim($email);
        if ($email !== '') {
            $found = $this->client->findCustomerByEmail($connection, $email);
            $found = $this->unclaimedRemoteCustomer($ledger, $connection, $uuid, $found, null);
            if ($found !== null) {
                return $found;
            }
        }

        $name = CustomerMapper::displayName($displayName);
        if ($name === '') {
            return null;
        }
        $found = $this->client->findCustomerByDisplayName($connection, $name);

        return $this->unclaimedRemoteCustomer($ledger, $connection, $uuid, $found, null);
    }

    /**
     * @param array<string, mixed>             $connection
     * @param array<int, array<string, mixed>> $rows
     * @param array<string, mixed>             $settings
     * @param array<string, mixed>             $batch
     */
    private function syncCustomerBlock(SyncLedger $ledger, array &$connection, array $rows, array $settings, array &$batch, int $now, string $trigger, bool $record): bool
    {
        if ($this->entityEnabled($settings, 'customer') === false) {
            return $this->recordDisabledRows($ledger, $connection, $rows, $batch, $trigger, $record);
        }
        try {
            $this->prefetchCustomers($connection, $ledger, $rows);

            return $this->syncBufferedBlock($ledger, $connection, $rows, $settings, $batch, $now, $trigger, $record, 'customer');
        } catch (QuickBooksException $exception) {
            if ($exception->isUnauthorized() === true) {
                $this->tokenRejected($ledger, (string) $connection['company_uuid']);
                $batch['failed']++;

                return true;
            }
            if ($exception->isRateLimit() === true) {
                $this->failure($ledger, $connection, $rows[0], $exception, $settings, $now);
                if ($record === true) {
                    $this->recordRow($ledger, $connection, $rows[0], 'failed', $batch, $trigger, $this->exceptionError($exception));
                }

                return true;
            }

            throw $exception;
        } finally {
            $this->customerRemoteCache = null;
            if ($record === true) {
                $this->customerLookupFailed = [];
            }
        }
    }

    /**
     * @param array<string, mixed>             $connection
     * @param array<int, array<string, mixed>> $rows
     */
    private function prefetchCustomers(array $connection, SyncLedger $ledger, array $rows): void
    {
        $this->customerRemoteCache  = [];
        $this->customerLookupFailed = [];
        $companyUuid                = (string) $connection['company_uuid'];
        $realm                      = (string) $connection['realm_id'];
        /** @var array<int, array{bId: string, query: string, uuid: string, by: string, value: string}> $queries */
        $queries = [];
        /** @var array<int, array{bId: string, query: string, uuid: string, email: string, name: string}> $nameOnly */
        $nameOnly = [];
        foreach ($rows as $row) {
            $uuid = (string) ($row['local_uuid'] ?? '');
            if ($uuid === '') {
                continue;
            }
            $customer = $ledger->customers[$uuid] ?? null;
            $link     = $ledger->link($companyUuid, $realm, 'customer', $uuid);
            if (is_array($link) === true && (string) ($link['qbo_id'] ?? '') !== '') {
                $queries[] = [
                    'bId'   => 'customer:' . $uuid,
                    'query' => "select * from Customer where Id = '" . QuickBooksClient::escapeQuery((string) $link['qbo_id']) . "'",
                    'uuid'  => $uuid,
                    'by'    => 'id',
                    'value' => (string) $link['qbo_id'],
                ];
                continue;
            }
            // A deleted customer without a link is never matched or created.
            if (is_array($customer) === false || empty($customer['deleted_at']) === false) {
                continue;
            }
            $email = trim((string) ($customer['email'] ?? ''));
            if ($email !== '') {
                $queries[] = [
                    'bId'   => 'customer:' . $uuid,
                    'query' => "select * from Customer where PrimaryEmailAddr = '" . QuickBooksClient::escapeQuery($email) . "' maxresults 1",
                    'uuid'  => $uuid,
                    'by'    => 'email',
                    'value' => $email,
                ];
                continue;
            }
            $payload = $this->customers->toQuickBooks($this->customers->fromParty($customer));
            $name    = trim((string) ($payload['DisplayName'] ?? ''));
            if ($name !== '') {
                $nameOnly[] = [
                    'bId'   => 'customer-name:' . $uuid,
                    'query' => "select * from Customer where DisplayName = '" . QuickBooksClient::escapeQuery($name) . "' maxresults 1",
                    'uuid'  => $uuid,
                    'email' => '',
                    'name'  => $name,
                ];
            }
        }
        if ($queries === [] && $nameOnly === []) {
            return;
        }
        if ($queries !== [] && count($queries) === 1) {
            $this->prefetchOneCustomer($connection, $queries[0]);
        } elseif ($queries !== []) {
            $items = [];
            foreach ($queries as $query) {
                $items[] = ['bId' => $query['bId'], 'query' => $query['query']];
            }
            try {
                $results = $this->client->batch($connection, $items);
            } catch (QuickBooksException $exception) {
                if ($exception->isUnauthorized() === true || $exception->isRateLimit() === true) {
                    throw $exception;
                }
                foreach ($queries as $query) {
                    $this->customerLookupFailed[$query['uuid']] = $exception;
                }
                $results = [];
            }
            $seen = [];
            foreach ($results as $bId => $result) {
                $uuid = substr((string) $bId, strlen('customer:'));
                if ($uuid === '' || $uuid === (string) $bId) {
                    continue;
                }
                $seen[$uuid] = true;
                if (is_array($result) === true && empty($result['halt']) === false) {
                    throw new QuickBooksException((int) ($result['status'] ?? 0), (string) ($result['error'] ?? ''));
                }
                if (is_array($result) === false || empty($result['ok']) === true) {
                    $message                           = is_array($result) === true ? (string) ($result['error'] ?? '') : '';
                    $status                            = is_array($result) === true ? (int) ($result['status'] ?? 0) : 0;
                    $this->customerLookupFailed[$uuid] = $this->batchItemFailure($message, $status);
                    continue;
                }
                $remote                           = $result['rows'][0] ?? null;
                $this->customerRemoteCache[$uuid] = is_array($remote) === true ? $remote : null;
            }
            foreach ($queries as $query) {
                $uuid = $query['uuid'];
                if (isset($seen[$uuid]) === false && isset($this->customerLookupFailed[$uuid]) === false) {
                    $this->customerLookupFailed[$uuid] = $this->batchItemFailure(self::SYNC_FAILED_MESSAGE, 500);
                }
            }
        }

        $nameQueries = $this->nameQueriesAfterEmail($connection, $ledger, $queries);
        foreach ($nameOnly as $query) {
            if (isset($this->customerLookupFailed[$query['uuid']]) === false) {
                $nameQueries[] = $query;
            }
        }
        $this->prefetchCustomerNames($connection, $nameQueries);
    }

    /**
     * A display-name lookup for each customer whose email found no QuickBooks customer, or found
     * one another Fleetbase customer already syncs with: a free name match is the same customer.
     *
     * @param array<string, mixed>                                                                   $connection
     * @param array<int, array{bId: string, query: string, uuid: string, by: string, value: string}> $queries
     *
     * @return array<int, array{bId: string, query: string, uuid: string, email: string, name: string}>
     */
    private function nameQueriesAfterEmail(array $connection, SyncLedger $ledger, array $queries): array
    {
        $taken       = $this->takenEmailMatches($connection, $ledger, $queries);
        $nameQueries = [];
        foreach ($queries as $query) {
            $uuid = $query['uuid'];
            if ($query['by'] !== 'email' || isset($this->customerLookupFailed[$uuid]) === true
                || array_key_exists($uuid, $this->customerRemoteCache) === false) {
                continue;
            }
            $remote = $this->customerRemoteCache[$uuid];
            if ($remote !== null && isset($taken[(string) ($remote['Id'] ?? '')]) === false) {
                continue;
            }
            $customer = $ledger->customers[$uuid] ?? null;
            if (is_array($customer) === false) {
                continue;
            }
            $payload = $this->customers->toQuickBooks($this->customers->fromParty($customer));
            $name    = trim((string) ($payload['DisplayName'] ?? ''));
            if ($name !== '') {
                $nameQueries[] = [
                    'bId'   => 'customer-name:' . $uuid,
                    'query' => "select * from Customer where DisplayName = '" . QuickBooksClient::escapeQuery($name) . "' maxresults 1",
                    'uuid'  => $uuid,
                    'email' => trim((string) ($customer['email'] ?? '')),
                    'name'  => $name,
                ];
            }
        }

        return $nameQueries;
    }

    /**
     * The QuickBooks ids, as keys, that the email lookups found and that some Fleetbase customer
     * already syncs with. One read covers the whole block. These customers have no link on this
     * realm, so no link of their own needs excluding.
     *
     * @param array<string, mixed>                                                                   $connection
     * @param array<int, array{bId: string, query: string, uuid: string, by: string, value: string}> $queries
     *
     * @return array<string, true>
     */
    private function takenEmailMatches(array $connection, SyncLedger $ledger, array $queries): array
    {
        $ids = [];
        foreach ($queries as $query) {
            $remote = $query['by'] === 'email' ? ($this->customerRemoteCache[$query['uuid']] ?? null) : null;
            $id     = is_array($remote) === true ? (string) ($remote['Id'] ?? '') : '';
            if ($id !== '') {
                $ids[$id] = $id;
            }
        }
        if ($ids === []) {
            return [];
        }
        $taken = $ledger->remoteIdsLinked((string) $connection['realm_id'], 'Customer', array_values($ids), '');

        return array_fill_keys($taken, true);
    }

    /**
     * @param array<string, mixed>                                                       $connection
     * @param array{bId: string, query: string, uuid: string, by: string, value: string} $query
     */
    private function prefetchOneCustomer(array $connection, array $query): void
    {
        try {
            $remote = $query['by'] === 'id'
                ? $this->client->getCustomer($connection, $query['value'])
                : $this->client->findCustomerByEmail($connection, $query['value']);
        } catch (QuickBooksException $exception) {
            if ($exception->isUnauthorized() === true || $exception->isRateLimit() === true) {
                throw $exception;
            }
            $this->customerLookupFailed[$query['uuid']] = $exception;

            return;
        }
        $this->customerRemoteCache[$query['uuid']] = is_array($remote) === true ? $remote : null;
    }

    /**
     * @param array<string, mixed>                                                                     $connection
     * @param array<int, array{bId: string, query: string, uuid: string, email: string, name: string}> $queries
     */
    private function prefetchCustomerNames(array $connection, array $queries): void
    {
        foreach (array_chunk($queries, QuickBooksClient::BATCH_LIMIT) as $chunk) {
            if ($chunk === []) {
                continue;
            }
            if (count($chunk) === 1) {
                $query = $chunk[0];
                try {
                    $remote = $this->client->findCustomerByDisplayName($connection, $query['name']);
                } catch (QuickBooksException $exception) {
                    if ($exception->isUnauthorized() === true || $exception->isRateLimit() === true) {
                        throw $exception;
                    }
                    $this->customerLookupFailed[$query['uuid']] = $exception;
                    continue;
                }
                $this->rememberCustomerByName($query, is_array($remote) === true ? $remote : null);
                continue;
            }
            try {
                $results = $this->client->batch($connection, array_map(
                    static fn (array $query): array => ['bId' => $query['bId'], 'query' => $query['query']],
                    $chunk
                ));
            } catch (QuickBooksException $exception) {
                if ($exception->isUnauthorized() === true || $exception->isRateLimit() === true) {
                    throw $exception;
                }
                foreach ($chunk as $query) {
                    $this->customerLookupFailed[$query['uuid']] = $exception;
                }
                continue;
            }
            foreach ($chunk as $query) {
                $result = $results[$query['bId']] ?? null;
                if (is_array($result) === true && empty($result['halt']) === false) {
                    throw new QuickBooksException((int) ($result['status'] ?? 0), (string) ($result['error'] ?? ''));
                }
                if (is_array($result) === false || empty($result['ok']) === true) {
                    $message                                    = is_array($result) === true ? (string) ($result['error'] ?? '') : '';
                    $status                                     = is_array($result) === true ? (int) ($result['status'] ?? 0) : 0;
                    $this->customerLookupFailed[$query['uuid']] = $this->batchItemFailure($message, $status);
                    continue;
                }
                $remote = $result['rows'][0] ?? null;
                $this->rememberCustomerByName($query, is_array($remote) === true ? $remote : null);
            }
        }
    }

    /**
     * A display-name match is the same customer whatever the emails say. A miss is cached too,
     * so the row is not queried again one name at a time.
     *
     * @param array{bId: string, query: string, uuid: string, email: string, name: string} $query
     * @param array<string, mixed>|null                                                    $remote
     */
    private function rememberCustomerByName(array $query, ?array $remote): void
    {
        $this->customerRemoteCache[$query['uuid']] = $remote;
    }

    /**
     * QuickBooks fault 6240: the name is already used by another customer, a vendor or an
     * employee (DisplayName is unique across all three). Intuit's own text does not say
     * which customer or what to do, so this names the customer. Null for any other result.
     */
    private function customerNameTakenError(SyncLedger $ledger, string $kind, string $uuid, ?string $faultCode): ?string
    {
        if ($kind !== 'customer' || $faultCode !== self::DUPLICATE_NAME_FAULT) {
            return null;
        }
        $name = CustomerMapper::displayName((string) ($ledger->customers[$uuid]['name'] ?? ''));

        return Translation::encode('quickbooks.messages.customer-name-taken', ['name' => $name]);
    }

    /**
     * A QuickBooks customer has one currency, and QuickBooks refuses an invoice in another one.
     * A home-currency invoice is checked against a customer already read in this run only, so it
     * costs no extra read; a foreign-currency invoice reads its customer.
     *
     * @param array<string, mixed> $connection
     * @param array<string, mixed> $payload
     */
    private function customerCurrencyError(
        SyncLedger $ledger,
        array $connection,
        array $payload,
        string $currency,
    ): ?string {
        $currency    = strtoupper(trim($currency));
        $ref         = $payload['CustomerRef'] ?? null;
        $customerRef = is_array($ref) === true ? trim((string) ($ref['value'] ?? '')) : '';
        if ($currency === '' || $customerRef === '') {
            return null;
        }
        $customer = $this->taxCustomers[$customerRef] ?? null;
        $customer = is_array($customer) === true ? $customer : null;
        if ($customer === null && $currency !== $this->homeCurrency($ledger, $connection)) {
            $customer = $this->taxCustomer($connection, $customerRef);
        }
        $held             = is_array($customer['CurrencyRef'] ?? null) === true ? $customer['CurrencyRef'] : [];
        $customerCurrency = strtoupper(trim((string) ($held['value'] ?? '')));
        if ($customerCurrency === '' || $customerCurrency === $currency) {
            return null;
        }

        return Translation::encode('quickbooks.messages.invoice-customer-currency', [
            'currency'          => $currency,
            'customer_currency' => $customerCurrency,
            'name'              => (string) ($customer['DisplayName'] ?? ''),
        ]);
    }

    /**
     * Sync an invoice's customer, created in the invoice currency if it does not exist yet.
     *
     * @param array<string, mixed> $connection
     * @param array<string, mixed> $settings
     */
    private function syncCustomerFor(
        SyncLedger $ledger,
        array $connection,
        string $uuid,
        array $settings,
        string $currency,
    ): string {
        $this->customerCreateCurrency[$uuid] = $currency;
        try {
            return $this->syncCustomer($ledger, $connection, $uuid, $settings);
        } finally {
            unset($this->customerCreateCurrency[$uuid]);
        }
    }

    /**
     * The create payload of a customer whose first invoice is in a foreign currency carries that
     * currency, when QuickBooks multi-currency is on. Never sent on update: QuickBooks refuses a
     * currency change.
     *
     * @param array<string, mixed> $connection
     * @param array<string, mixed> $payload
     *
     * @return array<string, mixed>
     */
    private function withCreateCurrency(SyncLedger $ledger, array $connection, string $uuid, array $payload): array
    {
        $currency = strtoupper(trim($this->customerCreateCurrency[$uuid] ?? ''));
        if ($currency === '' || $currency === $this->homeCurrency($ledger, $connection)) {
            return $payload;
        }
        if ($this->client->multiCurrencyEnabled($connection) === false) {
            return $payload;
        }
        $payload['CurrencyRef'] = ['value' => $currency];

        return $payload;
    }
}
