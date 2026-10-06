<?php

namespace Fleetbase\Quickbooks\Tests\Support;

use Fleetbase\Quickbooks\Services\QuickBooksClient;
use Fleetbase\Quickbooks\Support\Amounts;
use Fleetbase\Quickbooks\Support\NextDocNumber;
use Fleetbase\Quickbooks\Support\QuickBooksException;

class FakeQuickBooks extends QuickBooksClient
{
    /** @var array<int, string> */
    public array $calls = [];

    /** @var array<string, array<string, mixed>> */
    public array $customers = [];

    /** @var array<string, array<string, mixed>> */
    public array $invoices = [];

    /** @var array<string, array<string, mixed>> */
    public array $accounts = [];

    /** @var array<int, array<int, array<string, mixed>>> */
    public array $customerPages = [];

    public int $creates = 0;

    public int $invoiceCreates = 0;

    public int $accountCreates = 0;

    public int $failOnCreate = 0;

    public ?\Throwable $failWith = null;

    public ?\Throwable $failCustomerLookup = null;

    public ?array $invoiceByDoc = null;

    public ?array $accountByAcctNum = null;

    /** @var array<string, array<string, mixed>> */
    public array $payments = [];

    /** @var array<int, array<string, mixed>> */
    public array $paymentPayloads = [];

    public ?string $homeCurrency = null;

    public bool $customTxnNumbers = false;

    public string $serviceItemId = '';

    /** @var array<int, array<string, mixed>> */
    public array $remoteInvoiceList = [];

    /**
     * @param array<string, mixed> $connection
     * @param array<string, mixed> $payload
     *
     * @return array<string, mixed>
     */
    public function createCustomer(array $connection, array $payload): array
    {
        $this->calls[] = 'createCustomer';
        $this->creates++;
        if ($this->failWith !== null && $this->creates === $this->failOnCreate) {
            throw $this->failWith;
        }

        $id                   = (string) $this->creates;
        $record               = array_merge($payload, ['Id' => $id, 'SyncToken' => '0']);
        $this->customers[$id] = $record;

        return $record;
    }

    /**
     * @param array<string, mixed> $connection
     * @param array<string, mixed> $payload
     *
     * @return array<string, mixed>
     */
    public function updateCustomer(array $connection, string $id, string $syncToken, array $payload): array
    {
        $this->calls[]        = 'updateCustomer';
        $record               = array_merge($payload, ['Id' => $id, 'SyncToken' => (string) ((int) $syncToken + 1)]);
        $this->customers[$id] = $record;

        return $record;
    }

    /**
     * @param array<string, mixed> $connection
     *
     * @return array<string, mixed>|null
     */
    public function getCustomer(array $connection, string $id): ?array
    {
        $this->calls[] = 'getCustomer:' . $id;

        return $this->customers[$id] ?? null;
    }

    /**
     * @param array<string, mixed> $connection
     * @param array<string, mixed> $payload
     *
     * @return array<string, mixed>
     */
    public function createInvoice(array $connection, array $payload): array
    {
        $this->calls[] = 'createInvoice';
        $this->invoiceCreates++;
        if (!$this->customTxnNumbers && empty($payload['DocNumber'])) {
            $payload['DocNumber'] = $this->autoDocNumber();
        }
        $id     = 'inv-' . $this->invoiceCreates;
        $record = array_merge($payload, [
            'Id'        => $id,
            'SyncToken' => '0',
            'TotalAmt'  => $this->lineTotal($payload),
        ]);
        $this->invoices[$id] = $record;

        return $record;
    }

    /**
     * @param array<string, mixed> $connection
     * @param array<string, mixed> $payload
     *
     * @return array<string, mixed>
     */
    public function updateInvoice(array $connection, string $id, string $syncToken, array $payload): array
    {
        $this->calls[] = 'updateInvoice';
        $record        = array_merge($this->invoices[$id] ?? [], $payload, [
            'Id'        => $id,
            'SyncToken' => (string) ((int) $syncToken + 1),
            'TotalAmt'  => $this->lineTotal($payload),
        ]);
        $this->invoices[$id] = $record;

        return $record;
    }

    /**
     * @param array<string, mixed> $connection
     *
     * @return array<string, mixed>|null
     */
    /**
     * @param array<string, mixed> $connection
     */
    public function nextInvoiceDocNumber(array $connection): ?string
    {
        $this->calls[] = 'nextInvoiceDocNumber';

        return $this->autoDocNumber();
    }

    /**
     * @param array<string, mixed> $connection
     */
    public function customTxnNumbers(array $connection): bool
    {
        $this->calls[] = 'customTxnNumbers';

        return $this->customTxnNumbers;
    }

    private function autoDocNumber(): ?string
    {
        $latest        = '';
        $latestValue   = -1;
        foreach ($this->invoices as $invoice) {
            $doc = trim((string) ($invoice['DocNumber'] ?? ''));
            if (preg_match('/(\d+)$/', $doc, $matches) === 1 && (int) $matches[1] >= $latestValue) {
                $latestValue = (int) $matches[1];
                $latest      = $doc;
            }
        }

        if ($latest === '') {
            return '1';
        }

        return NextDocNumber::after($latest);
    }

    /**
     * @param array<string, mixed> $connection
     *
     * @return array<string, mixed>|null
     */
    public function findInvoiceByDocNumber(array $connection, string $docNumber): ?array
    {
        $this->calls[] = 'findInvoiceByDocNumber';
        if ($this->invoiceByDoc !== null && ($this->invoiceByDoc['DocNumber'] ?? null) === $docNumber) {
            return $this->invoiceByDoc;
        }

        foreach ($this->invoices as $invoice) {
            if (($invoice['DocNumber'] ?? null) === $docNumber) {
                return $invoice;
            }
        }

        return null;
    }

    /**
     * @param array<string, mixed> $connection
     *
     * @return array<string, mixed>|null
     */
    public function getInvoice(array $connection, string $id): ?array
    {
        $this->calls[] = 'getInvoice:' . $id;

        return $this->invoices[$id] ?? null;
    }

    /**
     * @param array<string, mixed> $connection
     *
     * @return array<string, mixed>
     */
    public function voidInvoice(array $connection, string $id, string $syncToken): array
    {
        $this->calls[] = 'voidInvoice';

        $invoice = $this->invoices[$id] ?? ['Id' => $id];
        if (!empty($invoice['voided'])) {
            throw new QuickBooksException(400, 'QuickBooks request failed with status 400: The invoice is already voided.');
        }

        $invoice['Id']        = $id;
        $invoice['SyncToken'] = (string) ((int) $syncToken + 1);
        $invoice['voided']    = true;
        $this->invoices[$id]  = $invoice;

        return ['Id' => $id, 'SyncToken' => $invoice['SyncToken']];
    }

    /**
     * @param array<string, mixed> $connection
     * @param array<string, mixed> $payload
     *
     * @return array<string, mixed>
     */
    public function createPayment(array $connection, array $payload): array
    {
        $this->calls[]            = 'createPayment';
        $this->paymentPayloads[]  = $payload;
        $id                       = 'pay-' . (count($this->payments) + 1);
        $record                   = array_merge($payload, ['Id' => $id, 'SyncToken' => '0']);
        $this->payments[$id]      = $record;

        return $record;
    }

    /**
     * @param array<string, mixed> $connection
     * @param array<string, mixed> $payload
     *
     * @return array<string, mixed>
     */
    public function updatePayment(array $connection, string $id, string $syncToken, array $payload): array
    {
        $this->calls[] = 'updatePayment';
        $record        = array_merge($this->payments[$id] ?? [], $payload, [
            'Id'        => $id,
            'SyncToken' => (string) ((int) $syncToken + 1),
        ]);
        $this->payments[$id] = $record;

        return $record;
    }

    /**
     * @param array<string, mixed> $connection
     *
     * @return array<string, mixed>|null
     */
    public function getPayment(array $connection, string $id): ?array
    {
        $this->calls[] = 'getPayment:' . $id;

        return $this->payments[$id] ?? null;
    }

    /**
     * @param array<string, mixed>                                                                                                $connection
     * @param array<int, array{bId: string, operation?: string, entity?: string, payload?: array<string, mixed>, query?: string}> $items
     *
     * @return array<string, array{ok: bool, body: array<string, mixed>, rows: array<int, array<string, mixed>>, error: string|null, status: int, halt: bool}>
     */
    public function batch(array $connection, array $items): array
    {
        $this->calls[] = 'batch';
        $results       = [];
        foreach (self::batchChunks($items) as $chunk) {
            if (count($items) > 1) {
                $this->calls[] = 'batchChunk:' . count($chunk);
            }
            foreach ($chunk as $item) {
                $bId = (string) ($item['bId'] ?? '');
                try {
                    $results[$bId] = $this->batchItem($connection, $item);
                } catch (QuickBooksException $exception) {
                    $halt          = $exception->isUnauthorized() || $exception->isRateLimit();
                    $results[$bId] = [
                        'ok'     => false,
                        'body'   => [],
                        'rows'   => [],
                        'error'  => $exception->getMessage(),
                        'status' => $exception->status,
                        'halt'   => $halt,
                    ];
                    if ($halt) {
                        return $results;
                    }
                } catch (\Throwable $exception) {
                    $results[$bId] = [
                        'ok'     => false,
                        'body'   => [],
                        'rows'   => [],
                        'error'  => $exception->getMessage(),
                        'status' => 500,
                        'halt'   => false,
                    ];
                }
            }
        }

        return $results;
    }

    /**
     * @param array<string, mixed> $connection
     * @param array<int, string>   $customerIds
     *
     * @return array<int, array<string, mixed>>
     */
    public function findPaymentsForCustomers(array $connection, array $customerIds, bool $asBatch = false): array
    {
        $this->calls[] = ($asBatch || count($customerIds) > 1) ? 'findPaymentsForCustomers:batch' : 'findPaymentsForCustomers';

        return array_values($this->payments);
    }

    /**
     * @param array<string, mixed>                                                                                    $connection
     * @param array{bId: string, operation?: string, entity?: string, payload?: array<string, mixed>, query?: string} $item
     *
     * @return array{ok: bool, body: array<string, mixed>, rows: array<int, array<string, mixed>>, error: string|null, status: int, halt: bool}
     */
    private function batchItem(array $connection, array $item): array
    {
        if (isset($item['query']) && is_string($item['query'])) {
            return [
                'ok'     => true,
                'body'   => [],
                'rows'   => $this->rowsForQuery($item['query']),
                'error'  => null,
                'status' => 200,
                'halt'   => false,
            ];
        }

        $operation = (string) ($item['operation'] ?? 'create');
        $entity    = (string) ($item['entity'] ?? '');
        $payload   = is_array($item['payload'] ?? null) ? $item['payload'] : [];
        $body      = match ($entity . ':' . $operation) {
            'Customer:create' => $this->createCustomer($connection, $payload),
            'Customer:update' => $this->updateCustomer($connection, (string) ($payload['Id'] ?? ''), (string) ($payload['SyncToken'] ?? '0'), $payload),
            'Invoice:create'  => $this->createInvoice($connection, $payload),
            'Invoice:update'  => $this->updateInvoice($connection, (string) ($payload['Id'] ?? ''), (string) ($payload['SyncToken'] ?? '0'), $payload),
            'Invoice:void'    => $this->voidInvoice($connection, (string) ($payload['Id'] ?? ''), (string) ($payload['SyncToken'] ?? '0')),
            'Payment:create'  => $this->createPayment($connection, $payload),
            'Payment:update'  => $this->updatePayment($connection, (string) ($payload['Id'] ?? ''), (string) ($payload['SyncToken'] ?? '0'), $payload),
            'Account:create'  => $this->createAccount($connection, $payload),
            'Account:update'  => $this->updateAccount($connection, (string) ($payload['Id'] ?? ''), (string) ($payload['SyncToken'] ?? '0'), $payload),
            default           => throw new QuickBooksException(400, 'Unsupported batch item.'),
        };

        return [
            'ok'     => true,
            'body'   => $body,
            'rows'   => [],
            'error'  => null,
            'status' => 200,
            'halt'   => false,
        ];
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function rowsForQuery(string $query): array
    {
        $entity = 'Customer';
        if (preg_match('/from\s+(Customer|Invoice|Payment|Account|Item)\b/i', $query, $match) === 1) {
            $entity = ucfirst(strtolower($match[1]));
        }
        $store = match ($entity) {
            'Invoice' => $this->invoices,
            'Payment' => $this->payments,
            'Account' => $this->accounts,
            default   => $this->customers,
        };
        if (preg_match('/\\bId\\s+IN\\s*\\(([^)]*)\\)/i', $query, $match) === 1) {
            return $this->entitiesByQuotedIds($store, $match[1]);
        }
        if (preg_match("/\\bId\\s*=\\s*'((?:\\\\'|\\\\\\\\|[^'])*)'/i", $query, $match) === 1) {
            $id = str_replace(["\\'", '\\\\'], ["'", '\\'], $match[1]);

            return isset($store[$id]) ? [$store[$id]] : [];
        }
        if (preg_match('/DocNumber\\s+IN\\s*\\(([^)]*)\\)/i', $query, $match) === 1) {
            $wanted = $this->quotedValues($match[1]);
            $rows   = [];
            foreach ($store as $row) {
                if (in_array((string) ($row['DocNumber'] ?? ''), $wanted, true)) {
                    $rows[] = $row;
                }
            }

            return $rows;
        }
        if (preg_match('/CustomerRef\\s+IN\\s*\\(([^)]*)\\)/i', $query, $match) === 1 || preg_match("/CustomerRef\\s*=\\s*'/", $query) === 1) {
            return array_values($this->payments);
        }
        if (preg_match("/PrimaryEmailAddr\\s*=\\s*'((?:\\\\'|\\\\\\\\|[^'])*)'/", $query, $match) === 1) {
            $email = str_replace(["\\'", '\\\\'], ["'", '\\'], $match[1]);
            foreach ($store as $row) {
                if (($row['PrimaryEmailAddr']['Address'] ?? null) === $email) {
                    return [$row];
                }
            }

            return [];
        }
        if (preg_match("/DisplayName\\s*=\\s*'((?:\\\\'|\\\\\\\\|[^'])*)'/", $query, $match) === 1) {
            $name = str_replace(["\\'", '\\\\'], ["'", '\\'], $match[1]);
            foreach ($store as $row) {
                if (($row['DisplayName'] ?? null) === $name) {
                    return [$row];
                }
            }

            return [];
        }

        return [];
    }

    /**
     * @param array<string, array<string, mixed>> $store
     *
     * @return array<int, array<string, mixed>>
     */
    private function entitiesByQuotedIds(array $store, string $list): array
    {
        $rows = [];
        foreach ($this->quotedValues($list) as $id) {
            if (isset($store[$id])) {
                $rows[] = $store[$id];
            }
        }

        return $rows;
    }

    /**
     * @return array<int, string>
     */
    private function quotedValues(string $list): array
    {
        preg_match_all("/'((?:\\\\'|\\\\\\\\|[^'])*)'/", $list, $matches);
        $values = [];
        foreach ($matches[1] as $value) {
            $values[] = str_replace(["\\'", '\\\\'], ["'", '\\'], $value);
        }

        return $values;
    }

    /**
     * @param array<string, mixed> $connection
     *
     * @return array<string, mixed>|null
     */
    public function findPaymentForInvoice(array $connection, string $customerId, string $invoiceId): ?array
    {
        $this->calls[] = 'findPaymentForInvoice';
        foreach ($this->payments as $payment) {
            foreach ($payment['Line'] ?? [] as $line) {
                if (!is_array($line)) {
                    continue;
                }
                foreach ($line['LinkedTxn'] ?? [] as $txn) {
                    if (is_array($txn) && (string) ($txn['TxnId'] ?? '') === $invoiceId) {
                        return $payment;
                    }
                }
            }
        }

        return null;
    }

    /**
     * @param array<string, mixed> $connection
     *
     * @return array<string, mixed>|null
     */
    public function findCustomerByEmail(array $connection, string $email): ?array
    {
        $this->calls[] = 'findCustomerByEmail';
        if ($this->failCustomerLookup !== null) {
            throw $this->failCustomerLookup;
        }
        foreach ($this->customers as $customer) {
            if (($customer['PrimaryEmailAddr']['Address'] ?? null) === $email) {
                return $customer;
            }
        }

        return null;
    }

    /**
     * @param array<string, mixed> $connection
     *
     * @return array<string, mixed>|null
     */
    public function findCustomerByDisplayName(array $connection, string $name): ?array
    {
        $this->calls[] = 'findCustomerByDisplayName';
        if ($this->failCustomerLookup !== null) {
            throw $this->failCustomerLookup;
        }
        foreach ($this->customers as $customer) {
            if (($customer['DisplayName'] ?? null) === $name) {
                return $customer;
            }
        }

        return null;
    }

    /**
     * @param array<string, mixed> $connection
     * @param array<string, mixed> $payload
     *
     * @return array<string, mixed>
     */
    public function createAccount(array $connection, array $payload): array
    {
        $this->calls[] = 'createAccount';
        $this->accountCreates++;
        if ($this->failWith !== null && $this->accountCreates === $this->failOnCreate) {
            throw $this->failWith;
        }

        $id                   = 'acct-' . $this->accountCreates;
        $record               = array_merge($payload, ['Id' => $id, 'SyncToken' => '0']);
        $this->accounts[$id]  = $record;

        return $record;
    }

    /**
     * @param array<string, mixed> $connection
     * @param array<string, mixed> $payload
     *
     * @return array<string, mixed>
     */
    public function updateAccount(array $connection, string $id, string $syncToken, array $payload): array
    {
        $this->calls[]       = 'updateAccount';
        $record              = array_merge($this->accounts[$id] ?? [], $payload, [
            'Id'        => $id,
            'SyncToken' => (string) ((int) $syncToken + 1),
        ]);
        $this->accounts[$id] = $record;

        return $record;
    }

    /**
     * @param array<string, mixed> $connection
     *
     * @return array<string, mixed>|null
     */
    public function getAccount(array $connection, string $id): ?array
    {
        $this->calls[] = 'getAccount:' . $id;

        return $this->accounts[$id] ?? null;
    }

    /**
     * @param array<string, mixed> $connection
     *
     * @return array<string, mixed>|null
     */
    public function findAccountByAcctNum(array $connection, string $acctNum): ?array
    {
        $this->calls[] = 'findAccountByAcctNum';
        if ($this->accountByAcctNum !== null && ($this->accountByAcctNum['AcctNum'] ?? null) === $acctNum) {
            return $this->accountByAcctNum;
        }

        foreach ($this->accounts as $account) {
            if (($account['AcctNum'] ?? null) === $acctNum) {
                return $account;
            }
        }

        return null;
    }

    /**
     * @param array<string, mixed> $connection
     *
     * @return array<int, array<string, mixed>>
     */
    public function queryCustomers(array $connection, int $start, int $max): array
    {
        $this->calls[] = 'queryCustomers:' . $start;

        return $this->customerPages[$start] ?? [];
    }

    /**
     * @param array<string, mixed> $connection
     */
    public function homeCurrency(array $connection): ?string
    {
        $this->calls[] = 'homeCurrency';

        return $this->homeCurrency;
    }

    /**
     * @param array<string, mixed> $connection
     */
    public function ensureServiceItem(array $connection): string
    {
        $this->calls[] = 'ensureServiceItem';

        return $this->serviceItemId;
    }

    /**
     * @param array<string, mixed> $connection
     *
     * @return array<int, array<string, mixed>>
     */
    public function listInvoices(array $connection): array
    {
        $this->calls[] = 'listInvoices';

        return $this->remoteInvoiceList;
    }

    /**
     * @param array<string, mixed> $connection
     *
     * @return array<int, array<string, mixed>>
     */
    public function findAccountsByName(array $connection, string $name): array
    {
        $this->calls[] = 'findAccountsByName';
        $matches       = [];
        foreach ($this->accounts as $account) {
            if (trim((string) ($account['Name'] ?? '')) === trim($name)) {
                $matches[] = $account;
            }
        }

        return $matches;
    }

    /**
     * @param array<string, mixed> $payload
     */
    private function lineTotal(array $payload): string
    {
        $cents = 0;
        foreach ($payload['Line'] ?? [] as $line) {
            if (!is_array($line)) {
                continue;
            }
            $amount = $line['Amount'] ?? 0;
            if (is_float($amount)) {
                throw new \InvalidArgumentException('Money must not be a float.');
            }
            $cents += Amounts::toMinorUnits(is_int($amount) ? $amount : (string) $amount);
        }

        return Amounts::centsToDecimal($cents);
    }
}
