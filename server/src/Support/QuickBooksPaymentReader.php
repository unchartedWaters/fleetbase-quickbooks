<?php

namespace Fleetbase\Quickbooks\Support;

use Fleetbase\Quickbooks\Services\ConnectionTokens;
use Fleetbase\Quickbooks\Services\FleetbaseDirectory;
use Fleetbase\Quickbooks\Services\QuickBooksClient;

/**
 * Reads payments from QuickBooks for a webhook delivery and returns the QuickBooks invoice
 * ids each one applies to. Used only by the queued job that resolves payments.
 */
class QuickBooksPaymentReader
{
    /**
     * QuickBooks invoice ids applied by these payments. Null means that payment
     * read returned nothing. A failure here skips the payment; it does not fail the delivery.
     *
     * @param array<int, string> $paymentIds
     *
     * @return array<string, array<int, string>|null> null when that payment read returned nothing
     */
    public function invoiceIds(string $companyUuid, string $realmId, array $paymentIds): array
    {
        $ready = $this->paymentReadContext($companyUuid, $realmId, $paymentIds);
        if ($ready === null) {
            return [];
        }

        $read = $this->readPaymentsRefreshingOnce($ready['client'], $ready['connection'], $paymentIds);
        if ($read === null) {
            return [];
        }

        return $this->mappedPaymentInvoices($read);
    }

    /**
     * @param array<int, string> $paymentIds
     *
     * @return array{client: QuickBooksClient, connection: array<string, mixed>}|null
     */
    private function paymentReadContext(string $companyUuid, string $realmId, array $paymentIds): ?array
    {
        if ($paymentIds === [] || $realmId === '') {
            return null;
        }

        $services = $this->paymentReadServices();
        if ($services === null) {
            return null;
        }
        $connection = $this->paymentReadConnection($services['directory'], $companyUuid, $realmId);
        if ($connection === null) {
            return null;
        }

        return ['client' => $services['client'], 'connection' => $connection];
    }

    /**
     * @return array{directory: FleetbaseDirectory, client: QuickBooksClient}|null
     */
    private function paymentReadServices(): ?array
    {
        try {
            $directory = app(FleetbaseDirectory::class);
            $client    = app(QuickBooksClient::class);
        } catch (\Throwable) {
            return null;
        }
        if ($directory instanceof FleetbaseDirectory === false || $client instanceof QuickBooksClient === false) {
            return null;
        }

        return ['directory' => $directory, 'client' => $client];
    }

    /**
     * @return array<string, mixed>|null
     */
    private function paymentReadConnection(FleetbaseDirectory $directory, string $companyUuid, string $realmId): ?array
    {
        try {
            $connection = $directory->connection($companyUuid);
        } catch (\Throwable) {
            return null;
        }
        if (is_array($connection) === false || empty($connection['needs_reauth']) === false) {
            return null;
        }
        if ((string) ($connection['realm_id'] ?? '') !== $realmId) {
            return null;
        }

        return $connection;
    }

    /**
     * @param array{found: array<string, array<string, mixed>>, missing: array<string, true>} $read
     *
     * @return array<string, array<int, string>|null>
     */
    private function mappedPaymentInvoices(array $read): array
    {
        $mapped = [];
        foreach ($read['found'] as $paymentId => $remote) {
            $mapped[(string) $paymentId] = $this->invoiceQboIdsOnPayment($remote);
        }
        foreach ($read['missing'] as $paymentId => $missing) {
            if ($missing === true && array_key_exists((string) $paymentId, $mapped) === false) {
                $mapped[(string) $paymentId] = null;
            }
        }

        return $mapped;
    }

    /**
     * The payment read, retried once with a new access token when QuickBooks refuses the old one.
     * Null when the read fails.
     *
     * @param array<string, mixed> $connection
     * @param array<int, string>   $paymentIds
     *
     * @return array{found: array<string, array<string, mixed>>, missing: array<string, true>}|null
     */
    private function readPaymentsRefreshingOnce(QuickBooksClient $client, array $connection, array $paymentIds): ?array
    {
        try {
            return $this->readPayments($client, $connection, $paymentIds);
        } catch (QuickBooksException $exception) {
            // The access token can expire between the refresh and this read. Refresh and read once more.
            $fresh = $exception->isUnauthorized() === true ? $this->rotatedConnection($connection) : null;
            if ($fresh === null) {
                return null;
            }
            try {
                return $this->readPayments($client, $fresh, $paymentIds);
            } catch (\Throwable) {
                return null;
            }
        } catch (\Throwable) {
            return null;
        }
    }

    /**
     * The connection with a new access token after QuickBooks refused the old one, or null
     * when it cannot be refreshed. Intuit refusing the refresh token is recorded on the
     * connection by the refresh itself.
     *
     * @param array<string, mixed> $connection
     *
     * @return array<string, mixed>|null
     */
    private function rotatedConnection(array $connection): ?array
    {
        try {
            $tokens = app(ConnectionTokens::class);
            if ($tokens instanceof ConnectionTokens === false) {
                return null;
            }
            $fresh = $tokens->refreshNow($connection);
        } catch (\Throwable) {
            return null;
        }
        if (empty($fresh['needs_reauth']) === false || empty($fresh['refresh_error']) === false) {
            return null;
        }

        $unchanged = (string) ($fresh['access_token'] ?? '') === (string) ($connection['access_token'] ?? '');

        return $unchanged === true ? null : $fresh;
    }

    /**
     * @param array<string, mixed> $connection
     * @param array<int, string>   $paymentIds
     *
     * @return array{found: array<string, array<string, mixed>>, missing: array<string, true>}
     */
    private function readPayments(QuickBooksClient $client, array $connection, array $paymentIds): array
    {
        $paymentIds = array_values(array_unique(array_filter($paymentIds, static fn (string $paymentId): bool => $paymentId !== '')));
        if ($paymentIds === []) {
            return ['found' => [], 'missing' => []];
        }
        if (count($paymentIds) === 1) {
            $remote = $client->getPayment($connection, $paymentIds[0]);
            if (is_array($remote) === false) {
                return ['found' => [], 'missing' => [$paymentIds[0] => true]];
            }

            return ['found' => [$paymentIds[0] => $remote], 'missing' => []];
        }

        $found   = [];
        $missing = [];
        foreach (array_chunk($paymentIds, QuickBooksClient::BATCH_LIMIT) as $index => $chunk) {
            $this->readPaymentChunk($client, $connection, $index, $chunk, $found, $missing);
        }

        return ['found' => $found, 'missing' => $missing];
    }

    /**
     * One batch query for a chunk of payment ids. A failed query leaves the chunk out of both
     * found and missing; an id the query did not return is missing.
     *
     * @param array<string, mixed>                $connection
     * @param array<int, string>                  $chunk
     * @param array<string, array<string, mixed>> $found
     * @param array<string, true>                 $missing
     */
    private function readPaymentChunk(QuickBooksClient $client, array $connection, int $index, array $chunk, array &$found, array &$missing): void
    {
        $bId     = 'webhook-payments-' . $index;
        $results = $client->batch($connection, [[
            'bId'   => $bId,
            'query' => 'select * from Payment where Id IN (' . QuickBooksClient::quotedList($chunk) . ')',
        ]]);
        $result = $results[$bId] ?? null;
        if (is_array($result) === false || empty($result['ok']) === true || is_array($result['rows'] ?? null) === false) {
            return;
        }
        $seen = [];
        foreach ($result['rows'] as $remote) {
            if (is_array($remote) === false) {
                continue;
            }
            $paymentId = trim((string) ($remote['Id'] ?? ''));
            if ($paymentId !== '') {
                $found[$paymentId] = $remote;
                $seen[$paymentId]  = true;
            }
        }
        foreach ($chunk as $paymentId) {
            if (isset($seen[$paymentId]) === false) {
                $missing[$paymentId] = true;
            }
        }
    }

    /**
     * @param array<string, mixed> $remote
     *
     * @return array<int, string>
     */
    private function invoiceQboIdsOnPayment(array $remote): array
    {
        $lines = $remote['Line'] ?? null;
        if (is_array($lines) === false) {
            return [];
        }

        $ids = [];
        foreach ($lines as $line) {
            if (is_array($line) === false) {
                continue;
            }
            $this->collectInvoiceTxnIds($line['LinkedTxn'] ?? [], $ids);
        }

        return $ids;
    }

    /**
     * Adds the ids of the invoices in a payment line's linked transactions.
     *
     * @param array<int, string> $ids
     */
    private function collectInvoiceTxnIds(mixed $txns, array &$ids): void
    {
        if (is_array($txns) === false) {
            return;
        }
        foreach ($txns as $txn) {
            if (is_array($txn) === false) {
                continue;
            }
            $type = (string) ($txn['TxnType'] ?? '');
            if ($type !== '' && $type !== 'Invoice') {
                continue;
            }
            $txnId = trim((string) ($txn['TxnId'] ?? ''));
            if ($txnId !== '' && in_array($txnId, $ids, true) === false) {
                $ids[] = $txnId;
            }
        }
    }
}
