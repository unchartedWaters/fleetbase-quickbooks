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

    /** Applying a payment raises the SyncToken of each invoice it pays, as QuickBooks does. */
    public bool $paymentsBumpInvoices = false;

    public ?string $homeCurrency = null;

    public bool $customTxnNumbers = false;

    public bool $multiCurrency = false;

    /** Number new invoice lines on create and update, as QuickBooks does. */
    public bool $assignLineIds = false;

    public bool $failInvoiceUpdates = false;

    public string $serviceItemId = '';

    /**
     * Item ids made inactive in QuickBooks. A line that newly uses one is refused with fault 2500.
     *
     * @var array<int, string>
     */
    public array $inactiveItems = [];

    public ?\Throwable $failServiceItem = null;

    /** Refuses every invoice create and update, as an item fault. */
    public ?QuickBooksException $rejectInvoicesWith = null;

    /** @var array<int, array<string, mixed>> */
    public array $remoteInvoiceList = [];

    /**
     * How the company taxes invoices (InvoiceTax::settings). Sales tax is off unless a test turns it on.
     *
     * @var array{mode: string, automated: bool, rate: string|null}
     */
    public array $salesTax = ['mode' => 'off', 'automated' => false, 'rate' => null];

    /** With automated sales tax, the rate code QuickBooks chooses for an invoice sent with none. */
    public ?string $automatedRate = null;

    /**
     * Invoice create payloads as sent, before QuickBooks adds ids and tax.
     *
     * @var array<int, array<string, mixed>>
     */
    public array $sentInvoices = [];

    public ?\Throwable $failSalesTax = null;

    public int $salesTaxReads = 0;

    /**
     * QuickBooks products by id. Names are unique whatever their case, a product made inactive
     * is renamed "... (deleted)", and a query leaves inactive ones out unless it asks for them.
     *
     * @var array<string, array<string, mixed>>
     */
    public array $items = [];

    public int $itemReads = 0;

    public int $itemCreates = 0;

    /** @var array<int, array<string, mixed>> */
    public array $itemWrites = [];

    /** Refuses product creates with this error. */
    public ?QuickBooksException $failItemCreate = null;

    /** Item queries answer an older SyncToken, as when QuickBooks changes a product meanwhile. */
    public bool $staleItemReads = false;

    /**
     * Sales tax percent by invoice rate code. An invoice with one of these rates is taxed on its
     * lines coded TAX, as QuickBooks works it out.
     *
     * @var array<string, string>
     */
    public array $taxRates = [];

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
        $this->refuseInvoiceLines($payload, true);
        $this->invoiceCreates++;
        $this->sentInvoices[] = $payload;
        if ($this->customTxnNumbers === false && empty($payload['DocNumber']) === true) {
            $payload['DocNumber'] = $this->autoDocNumber();
        }
        $id      = 'inv-' . $this->invoiceCreates;
        $payload = $this->withLineIds($payload);
        $record  = $this->withTax(array_merge($payload, [
            'Id'        => $id,
            'SyncToken' => '0',
            'TotalAmt'  => $this->lineTotal($payload),
        ]));
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
        if ($this->failInvoiceUpdates === true) {
            throw new QuickBooksException(400, 'quickbooks.messages.batch-item-unsupported');
        }
        $this->refuseInvoiceLines($payload, false);
        $this->refuseUntaxedLineUpdate($this->invoices[$id] ?? [], $payload);
        $payload       = $this->withLineIds($payload);
        $record        = array_merge($this->invoices[$id] ?? [], $payload, [
            'Id'        => $id,
            'SyncToken' => (string) ((int) $syncToken + 1),
        ]);
        $record['TotalAmt']  = $this->lineTotal($payload, $record['TxnTaxDetail'] ?? null);
        $record              = $this->withTax($record);
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

    /**
     * @param array<string, mixed> $connection
     */
    public function multiCurrencyEnabled(array $connection): bool
    {
        $this->calls[] = 'multiCurrencyEnabled';

        return $this->multiCurrency;
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
        if (empty($invoice['voided']) === false) {
            throw new QuickBooksException(400, qbRequestFailed(400, 'The invoice is already voided.'));
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
        $record                   = array_merge($this->appliedPayment($payload), ['Id' => $id, 'SyncToken' => '0']);
        $this->payments[$id]      = $record;
        $this->bumpPaidInvoices($payload);

        return $record;
    }

    /**
     * As QuickBooks does, a payment line applies at most the invoice total; the rest of the
     * payment stays unapplied.
     *
     * @param array<string, mixed> $payload
     *
     * @return array<string, mixed>
     */
    private function appliedPayment(array $payload): array
    {
        $unapplied = 0;
        foreach (is_array($payload['Line'] ?? null) === true ? $payload['Line'] : [] as $index => $line) {
            $invoiceId = (string) ($line['LinkedTxn'][0]['TxnId'] ?? '');
            $total     = $this->invoices[$invoiceId]['TotalAmt'] ?? null;
            $amount    = Amounts::toMinorUnits((string) ($line['Amount'] ?? '0'));
            if ($total !== null && $amount > Amounts::toMinorUnits((string) $total)) {
                $unapplied += $amount - Amounts::toMinorUnits((string) $total);
                $payload['Line'][$index]['Amount']    = (string) $total;
            }
        }
        if ($unapplied > 0) {
            $payload['UnappliedAmt'] = Amounts::centsToDecimal($unapplied, '');
        }

        return $payload;
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
        $record        = array_merge($this->payments[$id] ?? [], $this->appliedPayment($payload), [
            'Id'        => $id,
            'SyncToken' => (string) ((int) $syncToken + 1),
        ]);
        $this->payments[$id] = $record;
        $this->bumpPaidInvoices($payload);

        return $record;
    }

    /**
     * @param array<string, mixed> $payload
     */
    private function bumpPaidInvoices(array $payload): void
    {
        if ($this->paymentsBumpInvoices === false) {
            return;
        }
        foreach (is_array($payload['Line'] ?? null) === true ? $payload['Line'] : [] as $line) {
            $invoiceId = (string) ($line['LinkedTxn'][0]['TxnId'] ?? '');
            if (isset($this->invoices[$invoiceId]) === true) {
                $token                                    = (int) ($this->invoices[$invoiceId]['SyncToken'] ?? 0);
                $this->invoices[$invoiceId]['SyncToken']  = (string) ($token + 1);
            }
        }
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
                    $halt          = $exception->isUnauthorized() === true || $exception->isRateLimit() === true;
                    $results[$bId] = [
                        'ok'     => false,
                        'body'   => [],
                        'rows'   => [],
                        'error'  => $exception->getMessage(),
                        'status' => $exception->status,
                        'halt'   => $halt,
                    ];
                    if ($exception->faultCode !== null) {
                        $results[$bId]['code'] = $exception->faultCode;
                    }
                    if ($halt === true) {
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
        $this->calls[] = ($asBatch === true || count($customerIds) > 1) ? 'findPaymentsForCustomers:batch' : 'findPaymentsForCustomers';

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
        if (isset($item['query']) === true && is_string($item['query']) === true) {
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
        $payload   = is_array($item['payload'] ?? null) === true ? $item['payload'] : [];
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
            'Item:create'     => $this->createItem($connection, $payload),
            'Item:update'     => $this->updateItem(
                $connection,
                (string) ($payload['Id'] ?? ''),
                (string) ($payload['SyncToken'] ?? '0'),
                $payload
            ),
            default           => throw new QuickBooksException(400, 'quickbooks.messages.batch-item-unsupported'),
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
            'Item'    => $this->queriedItems($query),
            default   => $this->queriedCustomers($query),
        };
        if ($entity === 'Item' && preg_match('/\\bName\\s+IN\\s*\\(([^)]*)\\)/i', $query, $match) === 1) {
            $wanted = array_map('mb_strtolower', $this->quotedValues($match[1]));
            $rows   = [];
            foreach ($store as $row) {
                if (in_array(mb_strtolower((string) ($row['Name'] ?? '')), $wanted, true) === true) {
                    $rows[] = $row;
                }
            }

            return $rows;
        }
        if (preg_match('/\\bId\\s+IN\\s*\\(([^)]*)\\)/i', $query, $match) === 1) {
            return $this->entitiesByQuotedIds($store, $match[1]);
        }
        if (preg_match("/\\bId\\s*=\\s*'((?:\\\\'|\\\\\\\\|[^'])*)'/i", $query, $match) === 1) {
            $id = str_replace(["\\'", '\\\\'], ["'", '\\'], $match[1]);

            return isset($store[$id]) === true ? [$store[$id]] : [];
        }
        if (preg_match('/DocNumber\\s+IN\\s*\\(([^)]*)\\)/i', $query, $match) === 1) {
            $wanted = $this->quotedValues($match[1]);
            $rows   = [];
            foreach ($store as $row) {
                if (in_array((string) ($row['DocNumber'] ?? ''), $wanted, true) === true) {
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
     * QuickBooks answers a Customer query with active customers only (checked in the sandbox),
     * unless the query asks for both with Active IN (true, false). A read by id returns either.
     *
     * @return array<string, array<string, mixed>>
     */
    private function queriedCustomers(string $query): array
    {
        if (preg_match('/Active\s+IN\s*\(\s*true\s*,\s*false\s*\)/i', $query) === 1) {
            return $this->customers;
        }

        return array_filter(
            $this->customers,
            static fn (array $customer): bool => ($customer['Active'] ?? true) !== false
        );
    }

    /**
     * QuickBooks answers an Item query with active products only, unless the query asks for
     * both with Active IN (true, false).
     *
     * @return array<string, array<string, mixed>>
     */
    private function queriedItems(string $query): array
    {
        $items = $this->staleItemReads === false ? $this->items : array_map(
            static fn (array $item): array => ['SyncToken' => '0'] + $item,
            array_map(static function (array $item): array {
                unset($item['SyncToken']);

                return $item;
            }, $this->items)
        );
        if (preg_match('/Active\s+IN\s*\(\s*true\s*,\s*false\s*\)/i', $query) === 1) {
            return $items;
        }

        return array_filter($items, static fn (array $item): bool => ($item['Active'] ?? true) !== false);
    }

    /**
     * @param array<string, mixed> $connection
     * @param array<string, mixed> $payload
     *
     * @return array<string, mixed>
     */
    public function createItem(array $connection, array $payload): array
    {
        $this->calls[] = 'createItem';
        $this->itemCreates++;
        $this->itemWrites[] = ['create' => $payload];
        if ($this->failItemCreate !== null) {
            throw $this->failItemCreate;
        }
        $this->refuseTakenItemName((string) ($payload['Name'] ?? ''), '');
        $id               = 'qb-item-' . $this->itemCreates;
        $record           = array_merge(['Active' => true], $payload, ['Id' => $id, 'SyncToken' => '0']);
        $this->items[$id] = $record;

        return $record;
    }

    /**
     * A sparse update. A stale SyncToken is refused (fault 5010), and an item made inactive is
     * renamed "... (deleted)", which frees its name.
     *
     * @param array<string, mixed> $connection
     * @param array<string, mixed> $payload
     *
     * @return array<string, mixed>
     */
    public function updateItem(array $connection, string $id, string $syncToken, array $payload): array
    {
        $this->calls[]      = 'updateItem';
        $this->itemWrites[] = ['update' => $payload];
        $stored             = $this->items[$id] ?? null;
        if ($stored === null) {
            throw new QuickBooksException(400, 'Object Not Found', null, '610');
        }
        if ((string) ($stored['SyncToken'] ?? '0') !== $syncToken) {
            throw new QuickBooksException(400, 'Stale Object Error', null, '5010');
        }
        unset($payload['Id'], $payload['SyncToken'], $payload['sparse']);
        $record = array_merge($stored, $payload, ['Id' => $id, 'SyncToken' => (string) ((int) $syncToken + 1)]);
        if (($record['Active'] ?? true) === false && ($stored['Active'] ?? true) !== false) {
            $record['Name'] = (string) ($record['Name'] ?? '') . ' (deleted)';
        }
        if (array_key_exists('Name', $payload) === true) {
            $this->refuseTakenItemName((string) $record['Name'], $id);
        }
        $this->items[$id] = $record;

        return $record;
    }

    /**
     * Product names are unique among top-level products, whatever the case. A sub-product may
     * share the name of a top-level one (checked in the sandbox).
     */
    private function refuseTakenItemName(string $name, string $exceptId): void
    {
        foreach ($this->items as $id => $item) {
            if (($item['SubItem'] ?? false) === true || isset($item['ParentRef']) === true) {
                continue;
            }
            $same = mb_strtolower(trim((string) ($item['Name'] ?? ''))) === mb_strtolower(trim($name));
            if ($id !== $exceptId && $same === true) {
                throw new QuickBooksException(400, 'Duplicate Name Exists Error', null, '6240');
            }
        }
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
            if (isset($store[$id]) === true) {
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
                if (is_array($line) === false) {
                    continue;
                }
                foreach ($line['LinkedTxn'] ?? [] as $txn) {
                    if (is_array($txn) === true && (string) ($txn['TxnId'] ?? '') === $invoiceId) {
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

        // QuickBooks account names are unique.
        $name = trim((string) ($payload['Name'] ?? ''));
        foreach ($this->accounts as $account) {
            if ($name !== '' && trim((string) ($account['Name'] ?? '')) === $name) {
                throw new QuickBooksException(400, 'The name supplied already exists.', null, '6240');
            }
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
        if ($this->failServiceItem !== null) {
            throw $this->failServiceItem;
        }

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
     * @param array<string, mixed> $connection
     *
     * @return array{mode: string, automated: bool, rate: string|null}
     */
    public function salesTax(array $connection): array
    {
        $this->salesTaxReads++;
        if ($this->failSalesTax !== null) {
            throw $this->failSalesTax;
        }

        return $this->salesTax;
    }

    /**
     * @param array<string, mixed> $connection
     *
     * @return array<string, mixed>|null
     */
    public function item(array $connection, string $id): ?array
    {
        $this->itemReads++;

        return $this->items[$id] ?? null;
    }

    /**
     * @param array<string, mixed> $connection
     */
    public function itemActive(array $connection, string $id): bool
    {
        $this->calls[] = 'itemActive';

        return in_array($id, $this->inactiveItems, true) === false;
    }

    /**
     * QuickBooks refuses an inactive item on a new invoice or a new line (fault 2500). An
     * existing line that already holds the item is accepted.
     *
     * @param array<string, mixed> $payload
     */
    private function refuseInvoiceLines(array $payload, bool $create): void
    {
        if ($this->rejectInvoicesWith !== null) {
            throw $this->rejectInvoicesWith;
        }
        foreach (is_array($payload['Line'] ?? null) === true ? $payload['Line'] : [] as $line) {
            $item     = is_array($line) === true
                ? (string) ($line['SalesItemLineDetail']['ItemRef']['value'] ?? '')
                : '';
            $new      = $create === true || trim((string) ($line['Id'] ?? '')) === '';
            $inactive = in_array(
                $item,
                $this->inactiveItems,
                true
            ) === true || ($this->items[$item]['Active'] ?? true) === false;
            if ($new === true && $inactive === true) {
                $message = 'Invalid Reference Id : you must restore Fleetbase service (deleted).';

                throw new QuickBooksException(400, $message, null, '2500');
            }
        }
    }

    /**
     * QuickBooks refuses new lines on an invoice that has a sales tax rate unless the update sends
     * that rate again (checked in the sandbox, fault 6000).
     *
     * @param array<string, mixed> $stored
     * @param array<string, mixed> $payload
     */
    private function refuseUntaxedLineUpdate(array $stored, array $payload): void
    {
        $rate = $stored['TxnTaxDetail']['TxnTaxCodeRef']['value'] ?? null;
        if ($rate === null || array_key_exists('Line', $payload) === false) {
            return;
        }
        if (($payload['TxnTaxDetail']['TxnTaxCodeRef']['value'] ?? null) === null) {
            $message = 'We\'re sorry, QuickBooks encountered an error while calculating tax.';

            throw new QuickBooksException(400, $message, null, '6000');
        }
    }

    /**
     * The tax QuickBooks works out for an invoice with a rate in taxRates: the percent of its
     * lines coded TAX, added to the total.
     *
     * @param array<string, mixed> $record
     *
     * @return array<string, mixed>
     */
    private function withTax(array $record): array
    {
        $rate    = (string) ($record['TxnTaxDetail']['TxnTaxCodeRef']['value'] ?? $this->automatedRate ?? '');
        $percent = $this->taxRates[$rate] ?? null;
        if ($percent === null) {
            return $record;
        }
        $taxable = 0;
        foreach (is_array($record['Line'] ?? null) === true ? $record['Line'] : [] as $line) {
            if (is_array($line) === true && ($line['SalesItemLineDetail']['TaxCodeRef']['value'] ?? '') === 'TAX') {
                $taxable += $this->minorUnits($line['Amount'] ?? 0);
            }
        }
        $tax                    = (int) round($taxable * (float) $percent / 100);
        $record['TxnTaxDetail'] = [
            'TxnTaxCodeRef' => ['value' => $rate],
            'TotalTax'      => Amounts::centsToDecimal($tax),
            'TaxLine'       => [[
                'Amount'        => Amounts::centsToDecimal($tax),
                'DetailType'    => 'TaxLineDetail',
                'TaxLineDetail' => ['PercentBased' => true, 'TaxPercent' => $percent],
            ]],
        ];
        $record['TotalAmt'] = $this->lineTotal(['Line' => $record['Line'] ?? []], $record['TxnTaxDetail']);

        return $record;
    }

    /**
     * @param array<string, mixed> $payload
     *
     * @return array<string, mixed>
     */
    private function withLineIds(array $payload): array
    {
        if ($this->assignLineIds === false || is_array($payload['Line'] ?? null) === false) {
            return $payload;
        }
        $next = 0;
        foreach ($payload['Line'] as $line) {
            $next = max($next, (int) (is_array($line) === true ? ($line['Id'] ?? 0) : 0));
        }
        foreach ($payload['Line'] as $index => $line) {
            if (is_array($line) === true && trim((string) ($line['Id'] ?? '')) === '') {
                $payload['Line'][$index]['Id'] = (string) ++$next;
            }
        }

        return $payload;
    }

    /**
     * Sales lines less discounts, plus the tax QuickBooks worked out itself (automated sales tax),
     * which a payload without TxnTaxDetail leaves in place on an update.
     *
     * @param array<string, mixed> $payload
     */
    private function lineTotal(array $payload, mixed $taxDetail = null): string
    {
        $cents = 0;
        foreach ($payload['Line'] ?? [] as $line) {
            if (is_array($line) === false) {
                continue;
            }
            $detail = (string) ($line['DetailType'] ?? '');
            // A subtotal repeats the sales lines above it. QuickBooks does not add it again.
            if ($detail === 'SubTotalLineDetail') {
                continue;
            }
            $amount = $this->minorUnits($line['Amount'] ?? 0);
            $cents += $detail === 'DiscountLineDetail' ? -$amount : $amount;
        }
        if (is_array($taxDetail) === true) {
            $cents += $this->minorUnits($taxDetail['TotalTax'] ?? 0);
        }

        return Amounts::centsToDecimal($cents);
    }

    private function minorUnits(mixed $amount): int
    {
        if (is_float($amount) === true) {
            throw new \InvalidArgumentException('Money must not be a float.');
        }

        return Amounts::toMinorUnits(is_int($amount) === true ? $amount : (string) $amount);
    }
}
