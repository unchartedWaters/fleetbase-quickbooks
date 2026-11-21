<?php

namespace Fleetbase\Quickbooks\Services;

use Fleetbase\Quickbooks\Support\FleetbaseTax;
use Fleetbase\Quickbooks\Support\InvoiceTax;

/**
 * Sends SyncEngine's QuickBooks calls through SyncEngine::runAuthorized.
 * The inner client stays the real or fake client; this brackets HTTP and repeats a call
 * once with a refreshed access token when QuickBooks answers 401.
 *
 * @phpstan-import-type Settings from InvoiceTax as InvoiceTaxSettings
 * @phpstan-import-type Rates from FleetbaseTax as FleetbaseTaxRates
 *
 * @SuppressWarnings("PHPMD.TooManyPublicMethods") It mirrors QuickBooksClient's public calls one to one.
 */
class QuickBooksHttpGate extends QuickBooksClient
{
    public function __construct(private QuickBooksClient $inner, private SyncEngine $engine)
    {
    }

    /**
     * The key must be held by the client that sends the request, or the write carries no requestid.
     *
     * @template T
     *
     * @param callable(): T $write
     *
     * @return T
     */
    public function withWriteKey(string $key, callable $write): mixed
    {
        return $this->inner->withWriteKey($key, $write);
    }

    public function homeCurrency(array $connection): ?string
    {
        return $this->engine->runAuthorized(
            $connection,
            fn (array $connection): ?string => $this->inner->homeCurrency($connection)
        );
    }

    public function customTxnNumbers(array $connection): bool
    {
        return $this->engine->runAuthorized(
            $connection,
            fn (array $connection): bool => $this->inner->customTxnNumbers($connection)
        );
    }

    public function multiCurrencyEnabled(array $connection): bool
    {
        $enabled = $this->engine->runAuthorized(
            $connection,
            fn (array $connection): bool => $this->inner->multiCurrencyEnabled($connection)
        );

        return $enabled === true;
    }

    public function createCustomer(array $connection, array $payload): array
    {
        return $this->engine->runAuthorized(
            $connection,
            fn (array $connection): array => $this->inner->createCustomer($connection, $payload)
        );
    }

    public function updateCustomer(array $connection, string $remoteId, string $syncToken, array $payload): array
    {
        return $this->engine->runAuthorized(
            $connection,
            fn (array $connection): array => $this->inner->updateCustomer($connection, $remoteId, $syncToken, $payload)
        );
    }

    public function getCustomer(array $connection, string $remoteId): ?array
    {
        return $this->engine->runAuthorized(
            $connection,
            fn (array $connection): ?array => $this->inner->getCustomer($connection, $remoteId)
        );
    }

    public function createInvoice(array $connection, array $payload): array
    {
        return $this->engine->runAuthorized(
            $connection,
            fn (array $connection): array => $this->inner->createInvoice($connection, $payload)
        );
    }

    public function updateInvoice(array $connection, string $remoteId, string $syncToken, array $payload): array
    {
        return $this->engine->runAuthorized(
            $connection,
            fn (array $connection): array => $this->inner->updateInvoice($connection, $remoteId, $syncToken, $payload)
        );
    }

    public function nextInvoiceDocNumber(array $connection): ?string
    {
        return $this->engine->runAuthorized(
            $connection,
            fn (array $connection): ?string => $this->inner->nextInvoiceDocNumber($connection)
        );
    }

    public function nextInvoiceDocNumbers(array $connection, int $count): array
    {
        return $this->engine->runAuthorized(
            $connection,
            fn (array $connection): array => $this->inner->nextInvoiceDocNumbers($connection, $count)
        );
    }

    public function findInvoiceByDocNumber(array $connection, string $docNumber): ?array
    {
        return $this->engine->runAuthorized(
            $connection,
            fn (array $connection): ?array => $this->inner->findInvoiceByDocNumber($connection, $docNumber)
        );
    }

    public function getInvoice(array $connection, string $remoteId): ?array
    {
        return $this->engine->runAuthorized(
            $connection,
            fn (array $connection): ?array => $this->inner->getInvoice($connection, $remoteId)
        );
    }

    public function voidInvoice(array $connection, string $remoteId, string $syncToken): array
    {
        return $this->engine->runAuthorized(
            $connection,
            fn (array $connection): array => $this->inner->voidInvoice($connection, $remoteId, $syncToken)
        );
    }

    public function createPayment(array $connection, array $payload): array
    {
        return $this->engine->runAuthorized(
            $connection,
            fn (array $connection): array => $this->inner->createPayment($connection, $payload)
        );
    }

    public function updatePayment(array $connection, string $remoteId, string $syncToken, array $payload): array
    {
        return $this->engine->runAuthorized(
            $connection,
            fn (array $connection): array => $this->inner->updatePayment($connection, $remoteId, $syncToken, $payload)
        );
    }

    public function getPayment(array $connection, string $remoteId): ?array
    {
        return $this->engine->runAuthorized(
            $connection,
            fn (array $connection): ?array => $this->inner->getPayment($connection, $remoteId)
        );
    }

    public function findPaymentForInvoice(array $connection, string $customerId, string $invoiceId): ?array
    {
        return $this->engine->runAuthorized(
            $connection,
            fn (array $connection): ?array => $this->inner->findPaymentForInvoice($connection, $customerId, $invoiceId)
        );
    }

    public function findPaymentsForCustomers(array $connection, array $customerIds, bool $asBatch = false): array
    {
        return $this->engine->runAuthorized(
            $connection,
            fn (array $connection): array => $this->inner->findPaymentsForCustomers($connection, $customerIds, $asBatch)
        );
    }

    public function findCustomerByDisplayName(array $connection, string $name): ?array
    {
        return $this->engine->runAuthorized(
            $connection,
            fn (array $connection): ?array => $this->inner->findCustomerByDisplayName($connection, $name)
        );
    }

    public function findCustomerByEmail(array $connection, string $email): ?array
    {
        return $this->engine->runAuthorized(
            $connection,
            fn (array $connection): ?array => $this->inner->findCustomerByEmail($connection, $email)
        );
    }

    public function createAccount(array $connection, array $payload): array
    {
        return $this->engine->runAuthorized(
            $connection,
            fn (array $connection): array => $this->inner->createAccount($connection, $payload)
        );
    }

    public function updateAccount(array $connection, string $remoteId, string $syncToken, array $payload): array
    {
        return $this->engine->runAuthorized(
            $connection,
            fn (array $connection): array => $this->inner->updateAccount($connection, $remoteId, $syncToken, $payload)
        );
    }

    public function getAccount(array $connection, string $remoteId): ?array
    {
        return $this->engine->runAuthorized(
            $connection,
            fn (array $connection): ?array => $this->inner->getAccount($connection, $remoteId)
        );
    }

    public function findAccountByAcctNum(array $connection, string $acctNum): ?array
    {
        return $this->engine->runAuthorized(
            $connection,
            fn (array $connection): ?array => $this->inner->findAccountByAcctNum($connection, $acctNum)
        );
    }

    public function findAccountsByName(array $connection, string $name): array
    {
        return $this->engine->runAuthorized(
            $connection,
            fn (array $connection): array => $this->inner->findAccountsByName($connection, $name)
        );
    }

    public function batch(array $connection, array $items): array
    {
        return $this->engine->runAuthorized(
            $connection,
            fn (array $connection): array => $this->inner->batch($connection, $items)
        );
    }

    public function salesTax(array $connection): array
    {
        /** @var InvoiceTaxSettings $settings */
        $settings = $this->engine->runAuthorized(
            $connection,
            fn (array $connection): array => $this->inner->salesTax($connection)
        );

        return $settings;
    }

    public function taxRates(array $connection): array
    {
        /** @var FleetbaseTaxRates $rates */
        $rates = $this->engine->runAuthorized(
            $connection,
            fn (array $connection): array => $this->inner->taxRates($connection)
        );

        return $rates;
    }

    public function item(array $connection, string $remoteId): ?array
    {
        /** @var array<string, mixed>|null $item */
        $item = $this->engine->runAuthorized(
            $connection,
            fn (array $connection): ?array => $this->inner->item($connection, $remoteId)
        );

        return $item;
    }

    public function createItem(array $connection, array $payload): array
    {
        /** @var array<string, mixed> $item */
        $item = $this->engine->runAuthorized(
            $connection,
            fn (array $connection): array => $this->inner->createItem($connection, $payload)
        );

        return $item;
    }

    public function updateItem(array $connection, string $remoteId, string $syncToken, array $payload): array
    {
        /** @var array<string, mixed> $item */
        $item = $this->engine->runAuthorized(
            $connection,
            fn (array $connection): array => $this->inner->updateItem($connection, $remoteId, $syncToken, $payload)
        );

        return $item;
    }

    public function ensureServiceItem(array $connection): string
    {
        return $this->engine->runAuthorized(
            $connection,
            fn (array $connection): string => $this->inner->ensureServiceItem($connection)
        );
    }

    public function itemActive(array $connection, string $remoteId): bool
    {
        $active = $this->engine->runAuthorized(
            $connection,
            fn (array $connection): bool => $this->inner->itemActive($connection, $remoteId)
        );

        return $active === true;
    }
}
