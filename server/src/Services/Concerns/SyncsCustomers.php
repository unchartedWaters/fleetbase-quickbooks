<?php

namespace Fleetbase\Quickbooks\Services\Concerns;

use Fleetbase\Quickbooks\Services\SyncLedger;
use Fleetbase\Quickbooks\Support\ContentHash;
use Fleetbase\Quickbooks\Support\CustomerMapper;
use Fleetbase\Quickbooks\Support\QuickBooksChanges;
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

        $plan    = $this->customerPlan($ledger, $connection, $uuid, $settings, $direction);
        $payload = $this->customers->toQuickBooks($this->customers->fromParty($customer));
        $email   = trim((string) ($customer['email'] ?? ''));
        $found   = $this->customerRemote($ledger, $connection, $uuid, $plan, $payload, $email);
        if ($found['outcome'] !== null) {
            return $found['outcome'];
        }
        if ($found['remote'] !== null) {
            return $this->updateRemoteCustomer($ledger, $connection, $uuid, $payload, $found['remote'], $plan);
        }

        if ($found['link'] !== null) {
            return 'aligned';
        }

        return $this->createRemoteCustomer($ledger, $connection, $uuid, $payload, $plan);
    }

    /**
     * How this customer syncs, and its link as it was before this sync.
     *
     * @param array<string, mixed> $connection
     * @param array<string, mixed> $settings
     *
     * @return array{
     *     settings: array<string, mixed>, copy: bool, push: bool, reference: string, conflict: string,
     *     pushClears: bool, baseline: array<string, mixed>|null
     * }
     */
    private function customerPlan(
        SyncLedger $ledger,
        array $connection,
        string $uuid,
        array $settings,
        string $direction,
    ): array {
        $copy     = $this->copiesRemote($direction);
        $push     = $this->pushesRemote($direction);
        $conflict = (string) ($settings['customer_conflict'] ?? 'fleetbase');
        $link     = $ledger->link(
            (string) $connection['company_uuid'],
            (string) $connection['realm_id'],
            'customer',
            $uuid
        );
        if ($link !== null && $link['realm_id'] !== $connection['realm_id']) {
            $link = null;
        }

        return [
            'settings'   => $settings,
            'copy'       => $copy,
            'push'       => $push,
            'reference'  => (string) ($settings['customer_reference'] ?? 'fleetbase'),
            'conflict'   => $conflict,
            'pushClears' => $this->sendsClears($conflict, $push, $copy),
            'baseline'   => $link,
        ];
    }

    /**
     * The QuickBooks customer for this customer: from the block lookup, by email or name when it
     * has no link, or by its link. A first match under QuickBooks reference is copied at once and
     * its outcome returned.
     *
     * @param array<string, mixed> $connection
     * @param array{
     *     settings: array<string, mixed>, copy: bool, push: bool, reference: string, conflict: string,
     *     pushClears: bool, baseline: array<string, mixed>|null
     * } $plan
     * @param array<string, mixed> $payload
     *
     * @return array{remote: array<string, mixed>|null, link: array<string, mixed>|null, outcome: string|null}
     */
    private function customerRemote(
        SyncLedger $ledger,
        array $connection,
        string $uuid,
        array $plan,
        array $payload,
        string $email,
    ): array {
        if (isset($this->customerLookupFailed[$uuid]) === true) {
            throw $this->customerLookupFailed[$uuid];
        }

        $link     = $plan['baseline'];
        $remote   = null;
        $cacheHit = $this->customerRemoteCache !== null && array_key_exists($uuid, $this->customerRemoteCache) === true;
        if ($cacheHit === true && is_array($this->customerRemoteCache[$uuid]) === true) {
            $cached = $this->customerRemoteCache[$uuid];
            $remote = $this->unclaimedRemoteCustomer($ledger, $connection, $uuid, $cached, $link);
            $link   = $this->adoptRemoteCustomer($ledger, $connection, $uuid, $remote, $link);
        } elseif ($cacheHit === false && $link === null) {
            $name   = (string) ($payload['DisplayName'] ?? '');
            $remote = $this->findRemoteCustomer($ledger, $connection, $uuid, $email, $name);
            if ($remote !== null) {
                $this->putMatchedLink($ledger, $connection, 'customer', $uuid, 'Customer', $remote);
                if ($plan['reference'] === 'quickbooks' && $plan['copy'] === true) {
                    $outcome = $this->copyMatchedCustomer($ledger, $connection, $uuid, $remote, $plan['settings']);

                    return ['remote' => $remote, 'link' => $link, 'outcome' => $outcome];
                }
            }
        } elseif ($cacheHit === false && $link !== null) {
            $remote = $this->client->getCustomer($connection, (string) $link['qbo_id']);
        }

        return ['remote' => $remote, 'link' => $link, 'outcome' => null];
    }

    /**
     * A first match under QuickBooks reference: copy the QuickBooks customer and store its link.
     *
     * @param array<string, mixed> $connection
     * @param array<string, mixed> $remote
     * @param array<string, mixed> $settings
     */
    private function copyMatchedCustomer(
        SyncLedger $ledger,
        array $connection,
        string $uuid,
        array $remote,
        array $settings,
    ): string {
        $before = $ledger->customers[$uuid];
        $this->applyCustomerFromRemote($ledger, $uuid, $remote);
        $inSync = $this->customerInSync($ledger, $uuid, $remote);
        $token  = $this->tokenAfterSync(null, $remote, $inSync, 'customer', $settings);
        $first  = $this->linkFrom($connection, 'customer', $uuid, 'Customer', $remote);
        $ledger->putLink(['sync_token' => $token] + $first);
        $this->followUpFirstLink($ledger, $connection, 'customer', $uuid, $token);

        return $ledger->customers[$uuid] !== $before ? 'updated' : 'aligned';
    }

    /**
     * No QuickBooks customer has this customer: create one.
     *
     * @param array<string, mixed> $connection
     * @param array<string, mixed> $payload
     * @param array{
     *     settings: array<string, mixed>, copy: bool, push: bool, reference: string, conflict: string,
     *     pushClears: bool, baseline: array<string, mixed>|null
     * } $plan
     */
    private function createRemoteCustomer(
        SyncLedger $ledger,
        array $connection,
        string $uuid,
        array $payload,
        array $plan,
    ): string {
        if ($this->lastError !== null) {
            return 'failed';
        }
        if ($plan['push'] === false) {
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

    /**
     * The customer has a QuickBooks customer: done when both match, after the QuickBooks name is
     * copied under QuickBooks reference; otherwise the difference is resolved.
     *
     * @param array<string, mixed> $connection
     * @param array<string, mixed> $payload
     * @param array<string, mixed> $remote
     * @param array{
     *     settings: array<string, mixed>, copy: bool, push: bool, reference: string, conflict: string,
     *     pushClears: bool, baseline: array<string, mixed>|null
     * } $plan
     */
    private function updateRemoteCustomer(
        SyncLedger $ledger,
        array $connection,
        string $uuid,
        array $payload,
        array $remote,
        array $plan,
    ): string {
        $baseline   = $plan['baseline'];
        $pushClears = $plan['pushClears'];
        // QuickBooks did not change the customer since the last sync: Fleetbase leads, clears included.
        if ($pushClears === false) {
            $pushClears = $this->fleetbaseLeads($plan['settings'], 'customer', $baseline, $remote);
        }
        if ($pushClears === true) {
            $payload = $this->customers->toQuickBooks($this->customers->fromParty($ledger->customers[$uuid]), true);
        }

        if ($this->customerMatches($payload, $remote) === true) {
            $copied = $plan['copy'] === true && $pushClears === false
                && $this->copyCustomerNotesAndAddress($ledger, $uuid, $remote) === true;
            $inSync = $this->customerInSync($ledger, $uuid, $remote);

            return $this->syncedIf($ledger, $connection, 'customer', $uuid, $remote, $copied, $inSync, $baseline);
        }

        if ($plan['reference'] === 'quickbooks' && $plan['copy'] === true) {
            $this->copyCustomerName($ledger, $uuid, $remote);
            $party   = $this->customers->fromParty($ledger->customers[$uuid]);
            $payload = $this->customers->toQuickBooks($party, $pushClears);
        }

        if ($this->customerMatches($payload, $remote) === true) {
            $inSync = $this->customerInSync($ledger, $uuid, $remote);

            return $this->synced($ledger, $connection, 'customer', $uuid, $remote, 'updated', $inSync, $baseline);
        }

        return $this->resolveCustomerDifference($ledger, $connection, $uuid, $payload, $remote, $plan);
    }

    /**
     * The customer differs from QuickBooks: copy QuickBooks, or send Fleetbase, by the direction
     * and conflict rule.
     *
     * @param array<string, mixed> $connection
     * @param array<string, mixed> $payload
     * @param array<string, mixed> $remote
     * @param array{
     *     settings: array<string, mixed>, copy: bool, push: bool, reference: string, conflict: string,
     *     pushClears: bool, baseline: array<string, mixed>|null
     * } $plan
     */
    private function resolveCustomerDifference(
        SyncLedger $ledger,
        array $connection,
        string $uuid,
        array $payload,
        array $remote,
        array $plan,
    ): string {
        $baseline = $plan['baseline'];
        if ($plan['push'] === false && $plan['copy'] === true) {
            $before = $ledger->customers[$uuid];
            $this->applyCustomerFromRemote($ledger, $uuid, $remote);
            $changed = $ledger->customers[$uuid] !== $before;
            $inSync  = $this->customerInSync($ledger, $uuid, $remote);

            return $this->syncedIf($ledger, $connection, 'customer', $uuid, $remote, $changed, $inSync, $baseline);
        }

        // QuickBooks wins only a record it changed since the last sync; otherwise Fleetbase is sent.
        $remoteChanged = QuickBooksChanges::since($baseline, $remote);
        if ($plan['conflict'] === 'quickbooks' && $plan['copy'] === true && $remoteChanged === true) {
            $before = $ledger->customers[$uuid];
            $clears = $this->copiesClears($plan['settings'], 'customer', $baseline, $remote);
            $this->applyCustomerFromRemote($ledger, $uuid, $remote, $clears);
            $changed = $ledger->customers[$uuid] !== $before;
            $this->notePrimaryCopy('customer', $uuid, true, $changed);
            $inSync = $this->customerInSync($ledger, $uuid, $remote);

            return $this->syncedIf($ledger, $connection, 'customer', $uuid, $remote, $changed, $inSync, $baseline);
        }

        if ($plan['reference'] === 'quickbooks') {
            $payload['DisplayName'] = (string) ($remote['DisplayName'] ?? $payload['DisplayName'] ?? '');
        }

        if ($plan['push'] === false) {
            return 'aligned';
        }

        $this->keepBillAddrId($payload, $remote);

        return $this->writeCustomerUpdate($ledger, $connection, $uuid, $payload, $remote);
    }

    /**
     * @param array<string, mixed> $connection
     * @param array<string, mixed> $payload
     * @param array<string, mixed> $remote
     */
    private function writeCustomerUpdate(
        SyncLedger $ledger,
        array $connection,
        string $uuid,
        array $payload,
        array $remote,
    ): string {
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

        $updated = $this->client->updateCustomer(
            $connection,
            (string) $remote['Id'],
            (string) $remote['SyncToken'],
            $payload
        );
        $ledger->putLink($this->linkFrom($connection, 'customer', $uuid, 'Customer', $updated));

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
