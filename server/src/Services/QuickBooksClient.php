<?php

namespace Fleetbase\Quickbooks\Services;

use Fleetbase\Quickbooks\Support\NextDocNumber;
use Fleetbase\Quickbooks\Support\QuickBooksException;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;

class QuickBooksClient
{
    public const TRANSPORT_MESSAGE = 'QuickBooks could not be reached. Try again in a few minutes.';

    public const BATCH_LIMIT = 30;

    public const UPDATE_BATCH_SIZE = 20;

    /** @var array<string, array<string, mixed>> */
    private array $preferencesByRealm = [];

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
    public function exchangeCode(array $credentials, string $code, ?string $codeVerifier = null): array
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
    public function refresh(array $credentials, string $refreshToken): array
    {
        return $this->tokenRequest($credentials, [
            'grant_type'    => 'refresh_token',
            'refresh_token' => $refreshToken,
        ]);
    }

    /**
     * @param array<string, mixed> $connection
     *
     * @return array<string, mixed>
     */
    public function companyInfo(array $connection): array
    {
        $response = $this->accounting($connection, 'get', 'companyinfo/' . $connection['realm_id']);
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

        $numbers    = [];
        $checksLeft = $count + 5;
        while (count($numbers) < $count && $candidate !== null && $checksLeft > 0) {
            $window = [];
            $cursor = $candidate;
            $room   = min(($count - count($numbers)) + 5, $checksLeft);
            while (count($window) < $room && $cursor !== null) {
                $window[] = $cursor;
                $cursor   = NextDocNumber::after($cursor);
            }
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
            $candidate = $cursor;
        }

        return $numbers;
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
                $message = is_array($result) === true ? (string) ($result['error'] ?? 'QuickBooks query failed.') : 'QuickBooks query failed.';
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
        $invoiceIds = array_values(array_unique(array_filter(array_map(
            static fn (mixed $id): string => trim((string) $id),
            $invoiceIds
        ), static fn (string $id): bool => $id !== '')));
        if ($invoiceIds === []) {
            return [];
        }

        $invoices = [];
        if (count($invoiceIds) === 1) {
            $invoice = $this->getInvoice($connection, $invoiceIds[0]);
            if (is_array($invoice) === true) {
                $invoices[] = $invoice;
            }
        } else {
            $items = [];
            foreach (array_chunk($invoiceIds, self::BATCH_LIMIT) as $index => $chunk) {
                $items[] = [
                    'bId'   => 'payment-invoices-' . $index,
                    'query' => 'select * from Invoice where Id IN (' . self::quotedList($chunk) . ')',
                ];
            }
            foreach ($this->batch($connection, $items) as $result) {
                if (is_array($result) === false || empty($result['ok']) === true) {
                    $status  = is_array($result) === true ? (int) ($result['status'] ?? 400) : 400;
                    $message = is_array($result) === true ? (string) ($result['error'] ?? 'QuickBooks invoice lookup failed.') : 'QuickBooks invoice lookup failed.';
                    throw new QuickBooksException($status, $message);
                }
                foreach ($result['rows'] as $invoice) {
                    if (is_array($invoice) === true) {
                        $invoices[] = $invoice;
                    }
                }
            }
        }

        $paymentIds = [];
        foreach ($invoices as $invoice) {
            foreach ($this->linkedPaymentIds($invoice) as $id) {
                $paymentIds[$id] = true;
            }
        }
        $ids = array_keys($paymentIds);
        if ($ids === []) {
            return [];
        }
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
        $payments = [];
        foreach ($this->batch($connection, $items) as $result) {
            if (is_array($result) === false || empty($result['ok']) === true) {
                $status  = is_array($result) === true ? (int) ($result['status'] ?? 400) : 400;
                $message = is_array($result) === true ? (string) ($result['error'] ?? 'QuickBooks payment lookup failed.') : 'QuickBooks payment lookup failed.';
                throw new QuickBooksException($status, $message);
            }
            foreach ($result['rows'] as $payment) {
                if (is_array($payment) === true) {
                    $payments[] = $payment;
                }
            }
        }

        return $payments;
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
     * is kept. This does not re-send successes one record at a time. HTTP 401 and
     * 429 fail the chunk.
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
        foreach (self::batchChunks($items) as $chunk) {
            foreach ($this->postBatch($connection, $chunk) as $bId => $result) {
                $results[$bId] = $result;
            }
        }

        return $results;
    }

    /**
     * Updates stay in their own groups of UPDATE_BATCH_SIZE. Every other item
     * stays at the Batch API cap. Order is preserved, and a full group is not
     * split into single writes.
     *
     * @param array<int, array{bId?: string, operation?: string, entity?: string, payload?: array<string, mixed>, query?: string}> $items
     *
     * @return array<int, array<int, array{bId?: string, operation?: string, entity?: string, payload?: array<string, mixed>, query?: string}>>
     */
    public static function batchChunks(array $items): array
    {
        $chunks  = [];
        $current = [];
        $limit   = self::BATCH_LIMIT;
        foreach (array_values($items) as $item) {
            $itemLimit = ($item['operation'] ?? null) === 'update' ? self::UPDATE_BATCH_SIZE : self::BATCH_LIMIT;
            if ($current !== [] && ($itemLimit !== $limit || count($current) >= $limit)) {
                $chunks[] = $current;
                $current  = [];
            }
            $limit     = $itemLimit;
            $current[] = $item;
        }
        if ($current !== []) {
            $chunks[] = $current;
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
                    $message = is_array($one) === true ? (string) ($one['error'] ?? 'QuickBooks query failed.') : 'QuickBooks query failed.';
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
    private function tokenRequest(array $credentials, array $form): array
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
            if ($exception->isNotFound() === true) {
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

        $response = $this->accounting($connection, 'post', 'batch', ['BatchItemRequest' => $requests]);
        $body     = $this->decodeBody($response);
        $rows     = $body['BatchItemResponse'] ?? [];
        if (is_array($rows) === false) {
            $rows = [];
        }

        $results = [];
        foreach ($items as $item) {
            $results[(string) $item['bId']] = [
                'ok'     => false,
                'body'   => [],
                'rows'   => [],
                'error'  => 'QuickBooks batch response did not include this item.',
                'status' => 400,
                'halt'   => false,
            ];
        }
        foreach ($rows as $row) {
            if (is_array($row) === false) {
                continue;
            }
            $bId = (string) ($row['bId'] ?? '');
            if ($bId === '' || isset($results[$bId]) === false) {
                continue;
            }
            $results[$bId] = $this->batchItemResult($row);
        }

        return $results;
    }

    /**
     * @param array<string, mixed> $row
     *
     * @return array{ok: bool, body: array<string, mixed>, rows: array<int, array<string, mixed>>, error: string|null, status: int, halt: bool}
     */
    private function batchItemResult(array $row): array
    {
        $fault = $row['Fault']['Error'][0] ?? null;
        if (is_array($fault) === true) {
            $detail = trim((string) ($fault['Detail'] ?? $fault['Message'] ?? ''));
            $status = 400;

            return [
                'ok'     => false,
                'body'   => [],
                'rows'   => [],
                'error'  => $detail !== '' ? $detail : 'QuickBooks rejected this item.',
                'status' => $status,
                'halt'   => false,
            ];
        }

        if (isset($row['QueryResponse']) === true && is_array($row['QueryResponse']) === true) {
            $rows = [];
            foreach ($row['QueryResponse'] as $value) {
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

            return [
                'ok'     => true,
                'body'   => [],
                'rows'   => $rows,
                'error'  => null,
                'status' => 200,
                'halt'   => false,
            ];
        }

        foreach ($row as $key => $value) {
            if ($key === 'bId' || $key === 'Fault' || is_array($value) === false || array_is_list($value) === true) {
                continue;
            }

            return [
                'ok'     => true,
                'body'   => $value,
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
            'error'  => 'QuickBooks batch item had no entity.',
            'status' => 400,
            'halt'   => false,
        ];
    }

    /**
     * @param array<string, mixed>      $connection
     * @param array<string, mixed>|null $payload
     */
    private function accounting(array $connection, string $method, string $path, ?array $payload = null): Response
    {
        $base = ($connection['environment'] ?? 'sandbox') === 'production'
            ? 'https://quickbooks.api.intuit.com'
            : 'https://sandbox-quickbooks.api.intuit.com';
        $url       = $base . '/v3/company/' . $connection['realm_id'] . '/' . $path;
        $separator = str_contains($url, '?') === true ? '&' : '?';
        $url .= $separator . 'minorversion=75';

        try {
            $pending  = Http::withToken((string) $connection['access_token'])->acceptJson();
            $response = $method === 'get' ? $pending->get($url) : $pending->post($url, $payload ?? []);
        } catch (ConnectionException) {
            throw new QuickBooksException(0, self::TRANSPORT_MESSAGE);
        }

        $this->throwIfFailed($response);

        return $response;
    }

    private function throwIfFailed(Response $response): void
    {
        if ($response->successful() === true) {
            return;
        }

        $message   = 'QuickBooks request failed with status ' . $response->status();
        $fault     = $response->json('Fault.Error.0');
        $faultCode = null;
        if (is_array($fault) === true) {
            $detail = trim((string) ($fault['Detail'] ?? $fault['Message'] ?? ''));
            if ($detail !== '') {
                $message .= ': ' . $detail;
            }
            $faultCode = isset($fault['code']) === true ? (string) $fault['code'] : null;
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
