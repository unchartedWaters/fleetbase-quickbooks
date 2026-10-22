<?php

namespace Fleetbase\Quickbooks\Services;

use Fleetbase\Quickbooks\Support\NextDocNumber;
use Fleetbase\Quickbooks\Support\QuickBooksException;
use Fleetbase\Quickbooks\Support\Translation;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;

class QuickBooksClient
{
    public const TRANSPORT_MESSAGE = 'quickbooks.messages.transport';

    private const QUERY_FAILED = 'quickbooks.messages.query-failed';

    public const BATCH_LIMIT = 30;

    public const UPDATE_BATCH_SIZE = 20;

    /** Intuit's limit for the requestid and a batch item's bId together. */
    private const REQUEST_ID_FIELD_LIMIT = 50;

    /** @var array<string, array<string, mixed>> */
    private array $preferencesByRealm = [];

    /**
     * The Fleetbase record a single write belongs to (for example invoice:<uuid>), set by
     * withWriteKey. A single write without one carries no requestid.
     */
    private ?string $writeKey = null;

    /**
     * Run one write for a Fleetbase record, so its requestid names that record. Two records
     * whose QuickBooks bodies happen to be identical then never share a requestid.
     *
     * @template T
     *
     * @param callable(): T $write
     *
     * @return T
     */
    public function withWriteKey(string $key, callable $write): mixed
    {
        $previous       = $this->writeKey;
        $this->writeKey = $key;
        try {
            return $write();
        } finally {
            $this->writeKey = $previous;
        }
    }

    /**
     * QuickBooks query strings are single-quoted. A backslash and a quote inside
     * the value must both be escaped or the value closes the literal.
     */
    public static function escapeQuery(string $value): string
    {
        return str_replace(['\\', "'"], ['\\\\', "\\'"], $value);
    }

    /**
     * @param array{client_id: string, client_secret: string, redirect_uri: string, environment: string} $credentials
     */
    public function authorizationUrl(array $credentials, string $state, ?string $codeChallenge = null): string
    {
        $params = [
            'client_id'     => $credentials['client_id'],
            'redirect_uri'  => $credentials['redirect_uri'],
            'response_type' => 'code',
            'scope'         => 'com.intuit.quickbooks.accounting',
            'state'         => $state,
        ];
        if ($codeChallenge !== null && $codeChallenge !== '') {
            $params['code_challenge']        = $codeChallenge;
            $params['code_challenge_method'] = 'S256';
        }

        return 'https://appcenter.intuit.com/connect/oauth2?' . http_build_query($params);
    }

    /**
     * @param array{client_id: string, client_secret: string, redirect_uri: string, environment: string} $credentials
     *
     * @return array{access_token: string, refresh_token: string, expires_in: int}
     */
    public function exchangeCode(#[\SensitiveParameter] array $credentials, #[\SensitiveParameter] string $code, #[\SensitiveParameter] ?string $codeVerifier = null): array
    {
        $form = [
            'grant_type'   => 'authorization_code',
            'code'         => $code,
            'redirect_uri' => $credentials['redirect_uri'],
        ];
        if ($codeVerifier !== null && $codeVerifier !== '') {
            $form['code_verifier'] = $codeVerifier;
        }

        return $this->tokenRequest($credentials, $form);
    }

    /**
     * @param array{client_id: string, client_secret: string, redirect_uri: string, environment: string} $credentials
     *
     * @return array{access_token: string, refresh_token: string, expires_in: int}
     */
    public function refresh(#[\SensitiveParameter] array $credentials, #[\SensitiveParameter] string $refreshToken): array
    {
        return $this->tokenRequest($credentials, [
            'grant_type'    => 'refresh_token',
            'refresh_token' => $refreshToken,
        ]);
    }

    /**
     * Revoke a refresh token at Intuit, which also ends the access token issued with it.
     * The caller decides what a failure means; the token is never part of an error message.
     *
     * @param array{client_id: string, client_secret: string, redirect_uri: string, environment: string} $credentials
     */
    public function revoke(#[\SensitiveParameter] array $credentials, #[\SensitiveParameter] string $refreshToken): void
    {
        try {
            $response = Http::asJson()
                ->acceptJson()
                ->timeout(10)
                ->withBasicAuth($credentials['client_id'], $credentials['client_secret'])
                ->post('https://developer.api.intuit.com/v2/oauth2/tokens/revoke', ['token' => $refreshToken]);
        } catch (ConnectionException) {
            throw new QuickBooksException(0, self::TRANSPORT_MESSAGE);
        }

        $this->throwIfFailed($response);
    }

    /**
     * @param array<string, mixed> $connection
     *
     * @return array<string, mixed>
     */
    public function companyInfo(array $connection): array
    {
        $response = $this->accounting($connection, 'get', 'companyinfo/' . rawurlencode((string) $connection['realm_id']));
        $body     = $this->decodeBody($response);

        return is_array($body['CompanyInfo'] ?? null) === true ? $body['CompanyInfo'] : [];
    }

    /**
     * CompanyInfo has no home currency; it lives in Preferences.CurrencyPrefs.
     *
     * @param array<string, mixed> $connection
     */
    public function homeCurrency(array $connection): ?string
    {
        $value = $this->preferences($connection)['CurrencyPrefs']['HomeCurrency']['value'] ?? null;

        return is_string($value) === true && $value !== '' ? strtoupper($value) : null;
    }

    /**
     * Only a company with multi-currency turned on accepts an invoice in a foreign currency.
     *
     * @param array<string, mixed> $connection
     */
    public function multiCurrencyEnabled(array $connection): bool
    {
        $value = $this->preferences($connection)['CurrencyPrefs']['MultiCurrencyEnabled'] ?? false;

        return filter_var($value, FILTER_VALIDATE_BOOLEAN) === true;
    }

    /**
     * When this is off, QuickBooks assigns the invoice DocNumber itself on create.
     *
     * @param array<string, mixed> $connection
     */
    public function customTxnNumbers(array $connection): bool
    {
        return (bool) ($this->preferences($connection)['SalesFormsPrefs']['CustomTxnNumbers'] ?? false);
    }

    /**
     * @param array<string, mixed> $connection
     * @param array<string, mixed> $payload
     *
     * @return array<string, mixed>
     */
    public function createCustomer(array $connection, array $payload): array
    {
        return $this->entity($connection, 'post', 'customer', $payload, 'Customer');
    }

    /**
     * @param array<string, mixed> $connection
     * @param array<string, mixed> $payload
     *
     * @return array<string, mixed>
     */
    public function updateCustomer(array $connection, string $id, string $syncToken, array $payload): array
    {
        $payload['Id']        = $id;
        $payload['SyncToken'] = $syncToken;
        $payload['sparse']    = true;

        return $this->entity($connection, 'post', 'customer', $payload, 'Customer');
    }

    /**
     * @param array<string, mixed> $connection
     *
     * @return array<string, mixed>|null
     */
    public function getCustomer(array $connection, string $id): ?array
    {
        return $this->read($connection, 'customer/' . $id, 'Customer');
    }

    /**
     * @param array<string, mixed> $connection
     * @param array<string, mixed> $payload
     *
     * @return array<string, mixed>
     */
    public function createInvoice(array $connection, array $payload): array
    {
        return $this->entity($connection, 'post', 'invoice', $payload, 'Invoice');
    }

    /**
     * @param array<string, mixed> $connection
     * @param array<string, mixed> $payload
     *
     * @return array<string, mixed>
     */
    public function updateInvoice(array $connection, string $id, string $syncToken, array $payload): array
    {
        $payload['Id']        = $id;
        $payload['SyncToken'] = $syncToken;
        $payload['sparse']    = true;

        return $this->entity($connection, 'post', 'invoice', $payload, 'Invoice');
    }

    /**
     * The next unused invoice number after the latest QuickBooks invoice.
     * One latest-invoice query, then one existence query per candidate.
     * A block of creates uses nextInvoiceDocNumbers() instead of calling this once per invoice.
     *
     * @param array<string, mixed> $connection
     */
    public function nextInvoiceDocNumber(array $connection): ?string
    {
        $numbers = $this->nextInvoiceDocNumbers($connection, 1);

        return $numbers[0] ?? null;
    }

    /**
     * Distinct unused invoice numbers for a block of creates.
     * One latest-invoice query, then existence checks: one query when one candidate,
     * otherwise DocNumber IN through batch() in chunks of BATCH_LIMIT.
     * A number is returned only after that check says it is free. When the check
     * budget is exhausted, the result is shorter than $count and the caller fails
     * the creates that did not receive a number. Successes are not retried one
     * candidate at a time.
     *
     * @param array<string, mixed> $connection
     *
     * @return array<int, string>
     */
    public function nextInvoiceDocNumbers(array $connection, int $count): array
    {
        if ($count < 1) {
            return [];
        }

        $latest    = $this->latestInvoiceDocNumber($connection);
        $candidate = $latest === '' ? '1' : NextDocNumber::after($latest);
        if ($candidate === null) {
            return [];
        }

        if ($count === 1) {
            return $this->firstFreeInvoiceDocNumber($connection, $candidate);
        }

        return $this->freeInvoiceDocNumbers($connection, $candidate, $count);
    }

    /**
     * @param array<string, mixed> $connection
     *
     * @return array<int, string>
     */
    private function firstFreeInvoiceDocNumber(array $connection, string $candidate): array
    {
        for ($attempt = 0; $attempt < 5; $attempt++) {
            if ($this->findInvoiceByDocNumber($connection, $candidate) === null) {
                return [$candidate];
            }

            $next = NextDocNumber::after($candidate);
            if ($next === null) {
                return [];
            }

            $candidate = $next;
        }

        return [];
    }

    /**
     * @param array<string, mixed> $connection
     *
     * @return array<int, string>
     */
    private function freeInvoiceDocNumbers(array $connection, ?string $candidate, int $count): array
    {
        $numbers    = [];
        $checksLeft = $count + 5;
        while (count($numbers) < $count && $candidate !== null && $checksLeft > 0) {
            $room                 = min(($count - count($numbers)) + 5, $checksLeft);
            [$window, $candidate] = $this->invoiceDocNumberWindow($candidate, $room);
            if ($window === []) {
                break;
            }

            $taken = $this->takenInvoiceDocNumbers($connection, $window);
            $checksLeft -= count($window);
            foreach ($window as $value) {
                if (isset($taken[$value]) === false) {
                    $numbers[] = $value;
                }
                if (count($numbers) === $count) {
                    return $numbers;
                }
            }
        }

        return $numbers;
    }

    /**
     * @return array{0: array<int, string>, 1: ?string}
     */
    private function invoiceDocNumberWindow(?string $cursor, int $room): array
    {
        $window = [];
        while (count($window) < $room && $cursor !== null) {
            $window[] = $cursor;
            $cursor   = NextDocNumber::after($cursor);
        }

        return [$window, $cursor];
    }

    /**
     * @param array<string, mixed> $connection
     */
    protected function latestInvoiceDocNumber(array $connection): string
    {
        $rows = $this->query($connection, 'select DocNumber from Invoice orderby MetaData.CreateTime desc maxresults 1', 'Invoice');

        return trim((string) ($rows[0]['DocNumber'] ?? ''));
    }

    /**
     * @param array<string, mixed> $connection
     * @param array<int, string>   $candidates
     *
     * @return array<string, true>
     */
    private function takenInvoiceDocNumbers(array $connection, array $candidates): array
    {
        $candidates = array_values(array_unique(array_filter(
            $candidates,
            static fn (string $value): bool => $value !== ''
        )));
        if ($candidates === []) {
            return [];
        }
        if (count($candidates) === 1) {
            $found = $this->findInvoiceByDocNumber($connection, $candidates[0]);
            if ($found === null) {
                return [];
            }
            $doc = trim((string) ($found['DocNumber'] ?? ''));
            $key = $doc !== '' ? $doc : $candidates[0];

            return [$key => true];
        }

        $items = [];
        foreach (array_chunk($candidates, self::BATCH_LIMIT) as $index => $chunk) {
            $items[] = [
                'bId'   => 'doc-' . $index,
                'query' => 'select Id, DocNumber from Invoice where DocNumber IN (' . self::quotedList($chunk) . ')',
            ];
        }

        $taken = [];
        foreach ($this->batch($connection, $items) as $result) {
            if (is_array($result) === false || empty($result['ok']) === true) {
                $status  = is_array($result) === true ? (int) ($result['status'] ?? 400) : 400;
                $message = is_array($result) === true
                    ? (string) ($result['error'] ?? self::QUERY_FAILED)
                    : self::QUERY_FAILED;
                throw new QuickBooksException($status, $message);
            }
            foreach ($result['rows'] as $row) {
                if (is_array($row) === false) {
                    continue;
                }
                $doc = trim((string) ($row['DocNumber'] ?? ''));
                if ($doc !== '') {
                    $taken[$doc] = true;
                }
            }
        }

        return $taken;
    }

    /**
     * @param array<string, mixed> $connection
     *
     * @return array<string, mixed>|null
     */
    public function findInvoiceByDocNumber(array $connection, string $docNumber): ?array
    {
        $query   = "select * from Invoice where DocNumber = '" . self::escapeQuery($docNumber) . "'";
        $rows    = $this->query($connection, $query, 'Invoice');

        return $rows[0] ?? null;
    }

    /**
     * @param array<string, mixed> $connection
     *
     * @return array<string, mixed>|null
     */
    public function getInvoice(array $connection, string $id): ?array
    {
        return $this->read($connection, 'invoice/' . $id, 'Invoice');
    }

    /**
     * @param array<string, mixed> $connection
     *
     * @return array<string, mixed>
     */
    public function voidInvoice(array $connection, string $id, string $syncToken): array
    {
        $response = $this->accounting($connection, 'post', 'invoice?operation=void', [
            'Id'        => $id,
            'SyncToken' => $syncToken,
        ]);
        $body = $this->decodeBody($response);

        return is_array($body['Invoice'] ?? null) === true ? $body['Invoice'] : [];
    }

    /**
     * @param array<string, mixed> $connection
     * @param array<string, mixed> $payload
     *
     * @return array<string, mixed>
     */
    public function createPayment(array $connection, array $payload): array
    {
        return $this->entity($connection, 'post', 'payment', $payload, 'Payment');
    }

    /**
     * @param array<string, mixed> $connection
     * @param array<string, mixed> $payload
     *
     * @return array<string, mixed>
     */
    public function updatePayment(array $connection, string $id, string $syncToken, array $payload): array
    {
        $payload['Id']        = $id;
        $payload['SyncToken'] = $syncToken;
        $payload['sparse']    = true;

        return $this->entity($connection, 'post', 'payment', $payload, 'Payment');
    }

    /**
     * @param array<string, mixed> $connection
     *
     * @return array<string, mixed>|null
     */
    public function getPayment(array $connection, string $id): ?array
    {
        return $this->read($connection, 'payment/' . $id, 'Payment');
    }

    /**
     * A payment already applied to this invoice, if QuickBooks has one.
     *
     * @param array<string, mixed> $connection
     *
     * @return array<string, mixed>|null
     */
    public function findPaymentForInvoice(array $connection, string $customerId, string $invoiceId): ?array
    {
        $customerId = trim($customerId);
        $invoiceId  = trim($invoiceId);
        if ($customerId === '' || $invoiceId === '') {
            return null;
        }

        $payments = $this->findPaymentsForCustomers($connection, [$invoiceId => $customerId]);

        return $payments[0] ?? null;
    }

    /**
     * Payments linked to the target invoices. An associative input maps invoice id
     * to customer id and uses the invoices' LinkedTxn ids, so history is never scanned.
     * A legacy list input retains the customer query for callers without invoice scope.
     *
     * @param array<string, mixed>      $connection
     * @param array<int|string, string> $customerIds
     *
     * @return array<int, array<string, mixed>>
     */
    public function findPaymentsForCustomers(array $connection, array $customerIds, bool $asBatch = false): array
    {
        if (array_is_list($customerIds) === false) {
            return $this->findPaymentsForInvoiceTargets($connection, array_keys($customerIds));
        }

        $customerIds = array_values(array_unique(array_filter(array_map(
            static fn (mixed $id): string => trim((string) $id),
            $customerIds
        ), static fn (string $id): bool => $id !== '')));
        if ($customerIds === []) {
            return [];
        }

        if (count($customerIds) === 1) {
            $where = "CustomerRef = '" . self::escapeQuery($customerIds[0]) . "'";
        } else {
            $where = 'CustomerRef IN (' . self::quotedList($customerIds) . ')';
        }

        return $this->queryPages($connection, 'select * from Payment where ' . $where, 'Payment', $asBatch === true || count($customerIds) > 1);
    }

    /**
     * @param array<string, mixed> $connection
     * @param array<int, string>   $invoiceIds
     *
     * @return array<int, array<string, mixed>>
     */
    private function findPaymentsForInvoiceTargets(array $connection, array $invoiceIds): array
    {
        $invoiceIds = $this->trimmedIdList($invoiceIds);
        if ($invoiceIds === []) {
            return [];
        }

        $ids = $this->paymentIdsOnInvoices($this->invoicesForPaymentLookup($connection, $invoiceIds));
        if ($ids === []) {
            return [];
        }

        return $this->paymentsForIds($connection, $ids);
    }

    /**
     * @param array<int, mixed> $ids
     *
     * @return array<int, string>
     */
    private function trimmedIdList(array $ids): array
    {
        return array_values(array_unique(array_filter(array_map(
            static fn (mixed $id): string => trim((string) $id),
            $ids
        ), static fn (string $id): bool => $id !== '')));
    }

    /**
     * @param array<string, mixed> $connection
     * @param array<int, string>   $invoiceIds
     *
     * @return array<int, array<string, mixed>>
     */
    private function invoicesForPaymentLookup(array $connection, array $invoiceIds): array
    {
        if (count($invoiceIds) === 1) {
            $invoice = $this->getInvoice($connection, $invoiceIds[0]);

            return is_array($invoice) === true ? [$invoice] : [];
        }

        $items = [];
        foreach (array_chunk($invoiceIds, self::BATCH_LIMIT) as $index => $chunk) {
            $items[] = [
                'bId'   => 'payment-invoices-' . $index,
                'query' => 'select * from Invoice where Id IN (' . self::quotedList($chunk) . ')',
            ];
        }

        return $this->rowsFromBatch($connection, $items, 'quickbooks.messages.invoice-lookup-failed');
    }

    /**
     * @param array<int, array<string, mixed>> $invoices
     *
     * @return array<int, string>
     */
    private function paymentIdsOnInvoices(array $invoices): array
    {
        $paymentIds = [];
        foreach ($invoices as $invoice) {
            foreach ($this->linkedPaymentIds($invoice) as $paymentId) {
                $paymentIds[$paymentId] = true;
            }
        }

        return array_keys($paymentIds);
    }

    /**
     * @param array<string, mixed> $connection
     * @param array<int, string>   $ids
     *
     * @return array<int, array<string, mixed>>
     */
    private function paymentsForIds(array $connection, array $ids): array
    {
        if (count($ids) === 1) {
            $payment = $this->getPayment($connection, $ids[0]);

            return is_array($payment) === true ? [$payment] : [];
        }

        $items = [];
        foreach (array_chunk($ids, self::BATCH_LIMIT) as $index => $chunk) {
            $items[] = [
                'bId'   => 'payments-' . $index,
                'query' => 'select * from Payment where Id IN (' . self::quotedList($chunk) . ')',
            ];
        }

        return $this->rowsFromBatch($connection, $items, 'quickbooks.messages.payment-lookup-failed');
    }

    /**
     * @param array<string, mixed>                                                                                                $connection
     * @param array<int, array{bId: string, operation?: string, entity?: string, payload?: array<string, mixed>, query?: string}> $items
     *
     * @return array<int, array<string, mixed>>
     */
    private function rowsFromBatch(array $connection, array $items, string $fallback): array
    {
        $rows = [];
        foreach ($this->batch($connection, $items) as $result) {
            if (is_array($result) === false || empty($result['ok']) === true) {
                $status  = is_array($result) === true ? (int) ($result['status'] ?? 400) : 400;
                $message = is_array($result) === true ? (string) ($result['error'] ?? $fallback) : $fallback;
                throw new QuickBooksException($status, $message);
            }
            foreach ($result['rows'] as $row) {
                if (is_array($row) === true) {
                    $rows[] = $row;
                }
            }
        }

        return $rows;
    }

    /**
     * @param array<string, mixed> $invoice
     *
     * @return array<int, string>
     */
    private function linkedPaymentIds(array $invoice): array
    {
        $ids = [];
        foreach ($invoice['LinkedTxn'] ?? [] as $txn) {
            if (is_array($txn) === false || (string) ($txn['TxnType'] ?? '') !== 'Payment') {
                continue;
            }
            $id = trim((string) ($txn['TxnId'] ?? ''));
            if ($id !== '') {
                $ids[$id] = true;
            }
        }

        return array_keys($ids);
    }

    /**
     * @param array<string, mixed> $connection
     *
     * @return array<string, mixed>|null
     */
    public function findCustomerByDisplayName(array $connection, string $name): ?array
    {
        $name = trim($name);
        if ($name === '') {
            return null;
        }

        $rows = $this->query($connection, "select * from Customer where DisplayName = '" . self::escapeQuery($name) . "' maxresults 1", 'Customer');

        return $rows[0] ?? null;
    }

    /**
     * @param array<string, mixed> $connection
     *
     * @return array<string, mixed>|null
     */
    public function findCustomerByEmail(array $connection, string $email): ?array
    {
        $email = trim($email);
        if ($email === '') {
            return null;
        }

        $rows = $this->query($connection, "select * from Customer where PrimaryEmailAddr = '" . self::escapeQuery($email) . "' maxresults 1", 'Customer');

        return $rows[0] ?? null;
    }

    /**
     * @param array<string, mixed> $connection
     * @param array<string, mixed> $payload
     *
     * @return array<string, mixed>
     */
    public function createAccount(array $connection, array $payload): array
    {
        return $this->entity($connection, 'post', 'account', $payload, 'Account');
    }

    /**
     * @param array<string, mixed> $connection
     * @param array<string, mixed> $payload
     *
     * @return array<string, mixed>
     */
    public function updateAccount(array $connection, string $id, string $syncToken, array $payload): array
    {
        $payload['Id']        = $id;
        $payload['SyncToken'] = $syncToken;
        $payload['sparse']    = true;

        return $this->entity($connection, 'post', 'account', $payload, 'Account');
    }

    /**
     * @param array<string, mixed> $connection
     *
     * @return array<string, mixed>|null
     */
    public function getAccount(array $connection, string $id): ?array
    {
        return $this->read($connection, 'account/' . $id, 'Account');
    }

    /**
     * @param array<string, mixed> $connection
     *
     * @return array<string, mixed>|null
     */
    public function findAccountByAcctNum(array $connection, string $acctNum): ?array
    {
        $query   = "select * from Account where AcctNum = '" . self::escapeQuery($acctNum) . "'";
        $rows    = $this->query($connection, $query, 'Account');

        return $rows[0] ?? null;
    }

    /**
     * @param array<string, mixed> $connection
     *
     * @return array<int, array<string, mixed>>
     */
    public function findAccountsByName(array $connection, string $name): array
    {
        $name = trim($name);
        if ($name === '') {
            return [];
        }

        return $this->query($connection, "select * from Account where Name = '" . self::escapeQuery($name) . "'", 'Account');
    }

    /**
     * @param array<string, mixed> $connection
     *
     * @return array<int, array<string, mixed>>
     */
    public function queryCustomers(array $connection, int $start, int $max): array
    {
        $query = 'select * from Customer startposition ' . $start . ' maxresults ' . $max;

        return $this->query($connection, $query, 'Customer');
    }

    /**
     * @param array<string, mixed> $connection
     *
     * @return array<int, array<string, mixed>>
     */
    public function listInvoices(array $connection): array
    {
        // QuickBooks returns at most 1000 rows per query; startposition is 1-based.
        $pageSize = 1000;
        $start    = 1;
        $rows     = [];
        do {
            $page  = $this->query($connection, 'select Id, DocNumber, TotalAmt from Invoice startposition ' . $start . ' maxresults ' . $pageSize, 'Invoice');
            $rows  = array_merge($rows, $page);
            $start += $pageSize;
        } while (count($page) === $pageSize);

        return $rows;
    }

    /**
     * QuickBooks Batch API. Call this only when the set has more than one record.
     * A set of one uses the entity or query endpoint instead, so this method is not
     * the single-record path. Queries, creates, and voids are posted in chunks of 30.
     * Update operations are posted in chunks of UPDATE_BATCH_SIZE (20), not the
     * sync settings batch size. An item fault is returned and the rest of the chunk
     * is kept. This does not re-send successes one record at a time. An HTTP error on
     * the first chunk is thrown; on a later chunk the earlier results are kept and the
     * remaining items fail with that status.
     *
     * Each item is either an entity write:
     *   bId, operation (create|update|void|delete), entity (Customer|Invoice|Payment|Account|Item), payload
     * or a query:
     *   bId, query
     *
     * @param array<string, mixed>                                                                                                $connection
     * @param array<int, array{bId: string, operation?: string, entity?: string, payload?: array<string, mixed>, query?: string}> $items
     *
     * @return array<string, array{ok: bool, body: array<string, mixed>, rows: array<int, array<string, mixed>>, error: string|null, status: int, halt: bool}>
     */
    public function batch(array $connection, array $items): array
    {
        if ($items === []) {
            return [];
        }

        $results = [];
        $refused = null;
        $written = false;
        foreach (self::batchChunks($items) as $chunk) {
            if ($refused !== null) {
                $this->refuseChunk($results, $chunk, $refused);
                continue;
            }
            try {
                $chunkResults = $this->postBatch($connection, $chunk);
            } catch (QuickBooksException $exception) {
                // When no earlier chunk wrote anything (the first chunk, or only queries so far),
                // the caller may refresh the token or send everything again. After an earlier
                // chunk was written, any failure (401, 429, 5xx or a timeout) would repeat those
                // writes on a resend, so their results are kept and the rest fail as items to
                // retry later, with the status that stopped them.
                if ($written === false) {
                    throw $exception;
                }
                $refused = $exception;
                $this->refuseChunk($results, $chunk, $refused);
                continue;
            }
            foreach ($chunkResults as $bId => $result) {
                $results[$bId] = $result;
            }
            $written = $written === true || self::hasWrites($chunk);
        }

        return $results;
    }

    /**
     * @param array<int, array{
     *     bId?: string,
     *     operation?: string,
     *     entity?: string,
     *     payload?: array<string, mixed>,
     *     query?: string
     * }> $items
     */
    private static function hasWrites(array $items): bool
    {
        foreach ($items as $item) {
            $query = $item['query'] ?? null;
            if (is_string($query) === false || $query === '') {
                return true;
            }
        }

        return false;
    }

    /**
     * Item errors for a chunk that failed, or was not sent, after an earlier chunk was written.
     * They carry the failure's status, so a 429 still sets the rate-limit backoff. They do not
     * halt: a halting 401 asks the user to reconnect without trying a refresh, and a halt stops
     * the caller before it keeps the links of the items that were written.
     *
     * @param array<string, array{ok: bool, body: array<string, mixed>, rows: array<int, array<string, mixed>>, error: string|null, status: int, halt: bool}> $results
     * @param array<int, array{bId?: string, operation?: string, entity?: string, payload?: array<string, mixed>, query?: string}>                            $chunk
     */
    private function refuseChunk(array &$results, array $chunk, QuickBooksException $failure): void
    {
        foreach ($chunk as $item) {
            $results[(string) ($item['bId'] ?? '')] = [
                'ok'     => false,
                'body'   => [],
                'rows'   => [],
                'error'  => $failure->getMessage(),
                'status' => $failure->status,
                'halt'   => false,
            ];
        }
    }

    /**
     * Updates go in their own chunks of UPDATE_BATCH_SIZE. Every other item (creates,
     * voids, queries) goes in chunks of up to the Batch API cap. Items are grouped by
     * kind first, so creates and updates that interleave do not each start a chunk of
     * one. Order is kept within a kind; the kinds follow the order they first appear.
     * Results are matched back by bId, so the order across kinds does not matter.
     *
     * @param array<int, array{bId?: string, operation?: string, entity?: string, payload?: array<string, mixed>, query?: string}> $items
     *
     * @return array<int, array<int, array{bId?: string, operation?: string, entity?: string, payload?: array<string, mixed>, query?: string}>>
     */
    public static function batchChunks(array $items): array
    {
        $groups = [];
        foreach (array_values($items) as $item) {
            $kind            = ($item['operation'] ?? null) === 'update' ? 'update' : 'other';
            $groups[$kind][] = $item;
        }

        $chunks = [];
        foreach ($groups as $kind => $group) {
            $limit  = $kind === 'update' ? self::UPDATE_BATCH_SIZE : self::BATCH_LIMIT;
            $chunks = array_merge($chunks, array_chunk($group, $limit));
        }

        return $chunks;
    }

    /**
     * @param array<int, string> $values
     */
    public static function quotedList(array $values): string
    {
        $quoted = [];
        foreach ($values as $value) {
            $quoted[] = "'" . self::escapeQuery($value) . "'";
        }

        return implode(', ', $quoted);
    }

    /**
     * Page a query. A block (more than one id) goes through the Batch API.
     * A single id uses one query. A full page is not the end of the result.
     *
     * @param array<string, mixed> $connection
     *
     * @return array<int, array<string, mixed>>
     */
    public function queryPages(array $connection, string $baseQuery, string $key, bool $batched): array
    {
        $pageSize = 1000;
        $start    = 1;
        $rows     = [];
        $found    = [];
        do {
            $query = $baseQuery . ' startposition ' . $start . ' maxresults ' . $pageSize;
            if ($batched === true) {
                $page = $this->postBatch($connection, [['bId' => 'page-' . $start, 'query' => $query]]);
                $one  = $page['page-' . $start] ?? null;
                if (is_array($one) === false || empty($one['ok']) === true) {
                    $message = is_array($one) === true
                        ? (string) ($one['error'] ?? self::QUERY_FAILED)
                        : self::QUERY_FAILED;
                    $status  = is_array($one) === true ? (int) ($one['status'] ?? 400) : 400;
                    throw new QuickBooksException($status, $message);
                }
                $found = $one['rows'];
            } else {
                $found = $this->query($connection, $query, $key);
            }
            $rows = array_merge($rows, $found);
            $start += $pageSize;
        } while (count($found) === $pageSize);

        return $rows;
    }

    /**
     * Returns an empty string when the company has no Income account to post the item to.
     *
     * @param array<string, mixed> $connection
     */
    public function ensureServiceItem(array $connection): string
    {
        $existing = $this->query($connection, "select * from Item where Name = 'Fleetbase service'", 'Item');
        if (isset($existing[0]['Id']) === true) {
            return (string) $existing[0]['Id'];
        }

        $income = $this->query($connection, "select * from Account where AccountType = 'Income' maxresults 1", 'Account');
        if (isset($income[0]['Id']) === false) {
            return '';
        }

        $created = $this->entity($connection, 'post', 'item', [
            'Name'             => 'Fleetbase service',
            'Type'             => 'NonInventory',
            'IncomeAccountRef' => ['value' => (string) $income[0]['Id']],
        ], 'Item');

        return (string) ($created['Id'] ?? '');
    }

    /**
     * @param array{client_id: string, client_secret: string, redirect_uri: string, environment: string} $credentials
     * @param array<string, string>                                                                      $form
     *
     * @return array{access_token: string, refresh_token: string, expires_in: int}
     */
    private function tokenRequest(#[\SensitiveParameter] array $credentials, #[\SensitiveParameter] array $form): array
    {
        try {
            $response = Http::asForm()
                ->withBasicAuth($credentials['client_id'], $credentials['client_secret'])
                ->post('https://oauth.platform.intuit.com/oauth2/v1/tokens/bearer', $form);
        } catch (ConnectionException) {
            throw new QuickBooksException(0, self::TRANSPORT_MESSAGE);
        }

        $this->throwIfFailed($response);
        $body = $response->json();

        return [
            'access_token'  => (string) ($body['access_token'] ?? ''),
            'refresh_token' => (string) ($body['refresh_token'] ?? ''),
            'expires_in'    => (int) ($body['expires_in'] ?? 3600),
        ];
    }

    /**
     * @param array<string, mixed> $connection
     * @param array<string, mixed> $payload
     *
     * @return array<string, mixed>
     */
    private function entity(array $connection, string $method, string $path, array $payload, string $key): array
    {
        $response = $this->accounting($connection, $method, $path, $payload);
        $body     = $this->decodeBody($response);

        return is_array($body[$key] ?? null) === true ? $body[$key] : [];
    }

    /**
     * @param array<string, mixed> $connection
     *
     * @return array<string, mixed>|null
     */
    private function read(array $connection, string $path, string $key): ?array
    {
        try {
            $response = $this->accounting($connection, 'get', $path);
        } catch (QuickBooksException $exception) {
            // QuickBooks answers a GET for a customer id that does not exist with 2010, not 610.
            $missingCustomer = $key === 'Customer' && $exception->status === 400 && $exception->faultCode === '2010';
            if ($exception->isNotFound() === true || $missingCustomer === true) {
                return null;
            }

            throw $exception;
        }

        $body = $this->decodeBody($response);

        return is_array($body[$key] ?? null) === true ? $body[$key] : null;
    }

    /**
     * @param array<string, mixed> $connection
     *
     * @return array<int, array<string, mixed>>
     */
    private function query(array $connection, string $query, string $key): array
    {
        $response = $this->accounting($connection, 'get', 'query?query=' . rawurlencode($query));
        $body     = $this->decodeBody($response);
        $rows     = $body['QueryResponse'][$key] ?? [];

        return is_array($rows) === true ? array_values(array_filter($rows, 'is_array')) : [];
    }

    /**
     * @param array<string, mixed>                                                                                                $connection
     * @param array<int, array{bId: string, operation?: string, entity?: string, payload?: array<string, mixed>, query?: string}> $items
     *
     * @return array<string, array{ok: bool, body: array<string, mixed>, rows: array<int, array<string, mixed>>, error: string|null, status: int, halt: bool}>
     */
    private function postBatch(array $connection, array $items): array
    {
        $requests  = self::batchRequests($items);
        $keyed     = ['BatchItemRequest' => $requests];
        $requestId = $this->requestId((string) $connection['realm_id'], 'post', 'batch', $keyed);
        $wireIds   = self::wireBatchIds(array_column($requests, 'bId'), $requestId);
        foreach ($requests as $index => $request) {
            $requests[$index]['bId'] = $wireIds[$index];
        }
        $response = $this->accounting($connection, 'post', 'batch', ['BatchItemRequest' => $requests], $keyed);
        $body     = $this->decodeBody($response);
        $rows     = $body['BatchItemResponse'] ?? [];
        if (is_array($rows) === false) {
            $rows = [];
        }
        $callerIds = array_combine(
            $wireIds,
            array_map(static fn (array $item): string => (string) $item['bId'], $items)
        );

        $results = [];
        foreach ($items as $item) {
            $results[(string) $item['bId']] = [
                'ok'     => false,
                'body'   => [],
                'rows'   => [],
                'error'  => 'quickbooks.messages.batch-item-missing',
                'status' => 400,
                'halt'   => false,
            ];
        }
        $unauthorized = [];
        $matched      = [];
        $unnamedFault = null;
        foreach ($rows as $row) {
            if (is_array($row) === false) {
                continue;
            }
            $bId = $callerIds[(string) ($row['bId'] ?? '')] ?? null;
            if ($bId === null) {
                // Intuit leaves the bId off an item it refused before reading it, for example
                // over a requestid it will not accept. Accepted items always carry theirs.
                $unnamedFault ??= isset($row['Fault']) === true ? $row : null;
                continue;
            }
            $results[$bId] = $this->batchItemResult($row);
            $matched[$bId] = true;
            if (self::isAuthenticationFault($row) === true) {
                $unauthorized[$bId] = true;
            }
        }
        // Items QuickBooks did not answer by name share the fault it gave without one, so
        // Activity shows Intuit's reason rather than a missing answer.
        if ($unnamedFault !== null) {
            foreach (array_keys($results) as $bId) {
                if (isset($matched[$bId]) === false) {
                    $results[$bId] = $this->batchItemResult($unnamedFault);
                    if (self::isAuthenticationFault($unnamedFault) === true) {
                        $unauthorized[$bId] = true;
                    }
                }
            }
        }
        // QuickBooks can answer 200 with an authentication fault on each item instead of HTTP 401.
        // When every item was refused nothing in this chunk was written, so this is the same as
        // a 401 (batch() decides whether a retry is safe). A partly refused chunk is not a 401,
        // because the items that were accepted would be written twice.
        if ($unauthorized !== [] && count($unauthorized) === count($results)) {
            throw new QuickBooksException(401, 'quickbooks.messages.access-token-refused');
        }

        return $results;
    }

    /**
     * BatchItemRequest entries: a query, or an entity write with its operation and payload.
     *
     * @param array<int, array{bId: string, operation?: string, entity?: string, payload?: array<string, mixed>, query?: string}> $items
     *
     * @return array<int, array<string, mixed>>
     */
    private static function batchRequests(array $items): array
    {
        $requests = [];
        foreach ($items as $item) {
            $entry = ['bId' => (string) $item['bId']];
            if (isset($item['query']) === true && is_string($item['query']) === true && $item['query'] !== '') {
                $entry['Query'] = $item['query'];
            } else {
                $entity             = (string) ($item['entity'] ?? 'Customer');
                $entry['operation'] = (string) ($item['operation'] ?? 'create');
                $entry[$entity]     = is_array($item['payload'] ?? null) === true ? $item['payload'] : [];
            }
            $requests[] = $entry;
        }

        return $requests;
    }

    /**
     * The bIds sent to Intuit. Intuit joins the requestid and each bId into one field of at most
     * 50 characters, so when a caller bId (such as customer:<uuid>) would not fit, every item in
     * the chunk is sent under its position instead. Results are mapped back to the caller bIds.
     *
     * @param array<int, mixed> $callerIds
     *
     * @return array<int, string>
     */
    private static function wireBatchIds(array $callerIds, ?string $requestId): array
    {
        $callerIds = array_map('strval', array_values($callerIds));
        $room      = self::REQUEST_ID_FIELD_LIMIT - strlen((string) $requestId);
        foreach ($callerIds as $bId) {
            if (strlen($bId) > $room) {
                return array_map(static fn (int $index): string => 'b' . $index, array_keys($callerIds));
            }
        }

        return $callerIds;
    }

    /**
     * @param array<string, mixed> $row
     */
    private static function isAuthenticationFault(array $row): bool
    {
        $fault = $row['Fault'] ?? null;
        if (is_array($fault) === false) {
            return false;
        }
        if (strtoupper((string) ($fault['type'] ?? '')) === 'AUTHENTICATION') {
            return true;
        }
        $code = (string) ($fault['Error'][0]['code'] ?? '');

        return $code === '3200';
    }

    /**
     * @param array<string, mixed> $row
     *
     * @return array{
     *     ok: bool,
     *     body: array<string, mixed>,
     *     rows: array<int, array<string, mixed>>,
     *     error: string|null,
     *     status: int,
     *     halt: bool,
     *     code?: string
     * }
     */
    private function batchItemResult(array $row): array
    {
        $fault = $row['Fault']['Error'][0] ?? null;
        if (is_array($fault) === true) {
            return $this->batchFaultResult($fault, (string) ($row['Fault']['type'] ?? ''));
        }

        if (isset($row['QueryResponse']) === true && is_array($row['QueryResponse']) === true) {
            return $this->batchQueryResult($row['QueryResponse']);
        }

        $body = $this->batchEntityBody($row);
        if ($body !== null) {
            return [
                'ok'     => true,
                'body'   => $body,
                'rows'   => [],
                'error'  => null,
                'status' => 200,
                'halt'   => false,
            ];
        }

        return [
            'ok'     => false,
            'body'   => [],
            'rows'   => [],
            'error'  => 'quickbooks.messages.batch-item-no-entity',
            'status' => 400,
            'halt'   => false,
        ];
    }

    /**
     * @param array<string, mixed> $fault
     *
     * @return array{
     *     ok: bool,
     *     body: array<string, mixed>,
     *     rows: array<int, array<string, mixed>>,
     *     error: string|null,
     *     status: int,
     *     halt: bool,
     *     code?: string
     * }
     */
    private function batchFaultResult(array $fault, string $type = ''): array
    {
        $detail = trim((string) ($fault['Detail'] ?? $fault['Message'] ?? ''));
        $code   = trim((string) ($fault['code'] ?? ''));

        $result = [
            'ok'     => false,
            'body'   => [],
            'rows'   => [],
            'error'  => $detail !== '' ? $detail : 'quickbooks.messages.item-rejected',
            'status' => self::batchFaultStatus($type, $code),
            'halt'   => false,
        ];
        // Intuit's fault code (for example 6240, duplicate name) lets the engine explain the fault.
        if ($code !== '') {
            $result['code'] = $code;
        }

        return $result;
    }

    /**
     * The HTTP status a refused batch item stands for. A QuickBooks server fault may pass on
     * retry (503), and so may a stale SyncToken (5010), which only means the record changed in
     * QuickBooks after it was read (409). Other faults are the request's own (400).
     */
    private static function batchFaultStatus(string $type, string $code): int
    {
        if (strtoupper($type) === 'SYSTEMFAULT') {
            return 503;
        }

        return $code === '5010' ? 409 : 400;
    }

    /**
     * @param array<string, mixed> $response
     *
     * @return array{ok: bool, body: array<string, mixed>, rows: array<int, array<string, mixed>>, error: string|null, status: int, halt: bool}
     */
    private function batchQueryResult(array $response): array
    {
        return [
            'ok'     => true,
            'body'   => [],
            'rows'   => $this->batchQueryRows($response),
            'error'  => null,
            'status' => 200,
            'halt'   => false,
        ];
    }

    /**
     * @param array<string, mixed> $response
     *
     * @return array<int, array<string, mixed>>
     */
    private function batchQueryRows(array $response): array
    {
        $rows = [];
        foreach ($response as $value) {
            if (is_array($value) === false) {
                continue;
            }
            if (isset($value['Id']) === true) {
                $rows[] = $value;
                continue;
            }
            if (array_is_list($value) === true) {
                foreach ($value as $entity) {
                    if (is_array($entity) === true) {
                        $rows[] = $entity;
                    }
                }
            }
        }

        return $rows;
    }

    /**
     * @param array<string, mixed> $row
     *
     * @return array<string, mixed>|null
     */
    private function batchEntityBody(array $row): ?array
    {
        foreach ($row as $key => $value) {
            if ($key === 'bId' || $key === 'Fault' || is_array($value) === false || array_is_list($value) === true) {
                continue;
            }

            return $value;
        }

        return null;
    }

    /**
     * @param array<string, mixed>      $connection
     * @param array<string, mixed>|null $payload
     * @param array<string, mixed>|null $requestKey the body the requestid is derived from, when the
     *                                              body sent differs from it (a batch sent under
     *                                              position bIds)
     */
    private function accounting(
        #[\SensitiveParameter] array $connection,
        string $method,
        string $path,
        ?array $payload = null,
        ?array $requestKey = null,
    ): Response {
        $base = ($connection['environment'] ?? 'sandbox') === 'production'
            ? 'https://quickbooks.api.intuit.com'
            : 'https://sandbox-quickbooks.api.intuit.com';
        $url       = $base . '/v3/company/' . rawurlencode((string) $connection['realm_id']) . '/' . $path;
        $separator = str_contains($url, '?') === true ? '&' : '?';
        $url .= $separator . 'minorversion=75';
        $requestId = $this->requestId((string) $connection['realm_id'], $method, $path, $requestKey ?? $payload);
        if ($requestId !== null) {
            $url .= '&requestid=' . $requestId;
        }

        try {
            $pending  = Http::withToken((string) $connection['access_token'])->acceptJson();
            $response = $method === 'get' ? $pending->get($url) : $pending->post($url, $payload ?? []);
        } catch (ConnectionException) {
            throw new QuickBooksException(0, self::TRANSPORT_MESSAGE);
        }

        $this->throwIfFailed($response);

        return $response;
    }

    /**
     * Intuit applies a write whose requestid it has already seen only once and answers with
     * the first result, so a create that timed out after QuickBooks saved it is not saved
     * again when the run retries it. The id hashes the realm, the request, the Fleetbase
     * record (the bIds of a batch, or the key set by withWriteKey) and the UTC day: a retry
     * of the same write reuses it, while a changed body, another record or a later day (a
     * record deleted in QuickBooks and created again) gets a new one. Reads, a batch of
     * queries only, and a single write with no record key carry none. The id is a UUID-shaped
     * 36 characters; Intuit counts it together with each batch bId (see wireBatchIds).
     *
     * @param array<string, mixed>|null $payload
     */
    private function requestId(string $realmId, string $method, string $path, ?array $payload): ?string
    {
        if ($method === 'get') {
            return null;
        }
        $isBatch = $path === 'batch';
        if (
            ($isBatch === true && self::batchWrites($payload ?? []) === false)
            || ($isBatch === false && $this->writeKey === null)
        ) {
            return null;
        }

        $body = json_encode($payload ?? []);
        $hash = sha1(implode("\n", [
            $realmId,
            $method,
            $path,
            $isBatch === true ? '' : (string) $this->writeKey,
            is_string($body) === true ? $body : '',
            gmdate('Y-m-d'),
        ]));

        return implode('-', [
            substr($hash, 0, 8),
            substr($hash, 8, 4),
            substr($hash, 12, 4),
            substr($hash, 16, 4),
            substr($hash, 20, 12),
        ]);
    }

    /**
     * @param array<string, mixed> $payload
     */
    private static function batchWrites(array $payload): bool
    {
        $requests = $payload['BatchItemRequest'] ?? [];
        foreach (is_array($requests) === true ? $requests : [] as $request) {
            if (is_array($request) === true && isset($request['operation']) === true) {
                return true;
            }
        }

        return false;
    }

    private function throwIfFailed(Response $response): void
    {
        if ($response->successful() === true) {
            return;
        }

        $details   = [];
        $fault     = $response->json('Fault.Error.0');
        $faultCode = null;
        if (is_array($fault) === true) {
            $details[] = trim((string) ($fault['Detail'] ?? $fault['Message'] ?? ''));
            $faultCode = isset($fault['code']) === true ? (string) $fault['code'] : null;
        }

        // The OAuth token endpoint answers {"error":"invalid_grant"} with no Fault block. Keep only the short code, never error_description.
        $oauthError = $response->json('error');
        if (is_string($oauthError) === true && preg_match('/^[a-z_]{1,40}$/', $oauthError) === 1) {
            $details[] = $oauthError;
        }

        // Intuit's detail is its own English text, so it is a param and is shown as given.
        $detail  = implode(': ', array_filter($details, static fn (string $part): bool => $part !== ''));
        $status  = (string) $response->status();
        $message = $detail === ''
            ? Translation::encode('quickbooks.messages.request-failed', ['status' => $status])
            : Translation::encode(
                'quickbooks.messages.request-failed-detail',
                ['status' => $status, 'detail' => $detail]
            );
        // A 400 with Intuit's own explanation is a refusal of what was sent, not a failed call.
        $faultDetail = is_array($fault) === true ? trim((string) ($fault['Detail'] ?? $fault['Message'] ?? '')) : '';
        if ($response->status() === 400 && $faultDetail !== '' && is_string($oauthError) === false) {
            $message = Translation::encode('quickbooks.messages.request-rejected', ['detail' => $faultDetail]);
        }

        throw new QuickBooksException($response->status(), $message, $response->header('Retry-After'), $faultCode);
    }

    /**
     * Quote QuickBooks money fields before decode so JSON numbers stay strings, not floats.
     *
     * @return array<string, mixed>
     */
    private function decodeBody(Response $response): array
    {
        $json = preg_replace(
            '/"(Amount|TotalAmt|Balance|UnitPrice)"\s*:\s*(-?\d+(?:\.\d+)?(?:[eE][+-]?\d+)?)/',
            '"$1":"$2"',
            $response->body()
        );
        $decoded = json_decode(is_string($json) === true ? $json : $response->body(), true);

        return is_array($decoded) === true ? $decoded : [];
    }

    /**
     * @param array<string, mixed> $connection
     *
     * @return array<string, mixed>
     */
    private function preferences(array $connection): array
    {
        $realm = (string) ($connection['realm_id'] ?? '');
        if (array_key_exists($realm, $this->preferencesByRealm) === false) {
            $response                         = $this->accounting($connection, 'get', 'preferences');
            $body                             = $this->decodeBody($response);
            $prefs                            = $body['Preferences'] ?? [];
            $this->preferencesByRealm[$realm] = is_array($prefs) === true ? $prefs : [];
        }

        return $this->preferencesByRealm[$realm];
    }
}
