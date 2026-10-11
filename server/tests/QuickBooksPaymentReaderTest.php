<?php

use Fleetbase\Quickbooks\Services\FleetbaseDirectory;
use Fleetbase\Quickbooks\Services\QuickBooksClient;
use Fleetbase\Quickbooks\Support\QuickBooksPaymentReader;
use Fleetbase\Quickbooks\Tests\Support\FakeQuickBooks;
use Illuminate\Container\Container;

/**
 * Runs the reader with this client and a directory that returns this connection.
 *
 * @param array<string, mixed>|null $connection
 * @param array<int, string>        $paymentIds
 *
 * @return array<string, array<int, string>|null>
 */
function qprRead(QuickBooksClient $client, ?array $connection, array $paymentIds, string $realmId = 'realm-1'): array
{
    $directory = new class($connection) extends FleetbaseDirectory {
        public function __construct(private ?array $stored)
        {
        }

        public function connection(string $companyUuid): ?array
        {
            return $this->stored;
        }
    };
    $container         = Container::getInstance();
    $previousClient    = $container->bound(QuickBooksClient::class) === true ? $container->make(QuickBooksClient::class) : null;
    $previousDirectory = $container->bound(FleetbaseDirectory::class) === true ? $container->make(FleetbaseDirectory::class) : null;
    $container->instance(QuickBooksClient::class, $client);
    $container->instance(FleetbaseDirectory::class, $directory);

    try {
        return (new QuickBooksPaymentReader())->invoiceIds('company-a', $realmId, $paymentIds);
    } finally {
        if ($previousClient !== null) {
            $container->instance(QuickBooksClient::class, $previousClient);
        } else {
            $container->forgetInstance(QuickBooksClient::class);
        }
        if ($previousDirectory !== null) {
            $container->instance(FleetbaseDirectory::class, $previousDirectory);
        } else {
            $container->forgetInstance(FleetbaseDirectory::class);
        }
    }
}

/**
 * @return array<string, mixed>
 */
function qprConnection(): array
{
    return ['company_uuid' => 'company-a', 'realm_id' => 'realm-1', 'access_token' => 'access', 'needs_reauth' => false];
}

test('a batch read returns each payment\'s invoice ids and null for a payment quickbooks did not return', function () {
    $client                = new FakeQuickBooks();
    $client->payments['1'] = ['Id' => '1', 'Line' => [
        ['LinkedTxn' => [['TxnType' => 'Invoice', 'TxnId' => '10'], ['TxnType' => 'CreditMemo', 'TxnId' => '90']]],
    ]];
    $client->payments['2'] = ['Id' => '2', 'Line' => [
        ['LinkedTxn' => [['TxnType' => 'Invoice', 'TxnId' => '11']]],
        ['LinkedTxn' => [['TxnType' => 'Invoice', 'TxnId' => '11'], ['TxnId' => '12']]],
        ['LinkedTxn' => 'not-a-list'],
    ]];

    $found = qprRead($client, qprConnection(), ['1', '2', '3', '']);

    expect($found)->toBe(['1' => ['10'], '2' => ['11', '12'], '3' => null])
        ->and($client->calls)->toContain('batch')
        ->and($client->calls)->not->toContain('getPayment');
});

test('a failed batch query leaves its payments out instead of reporting them missing', function () {
    $client = new class extends FakeQuickBooks {
        public function batch(array $connection, array $items): array
        {
            $this->calls[] = 'batch';

            return [(string) $items[0]['bId'] => ['ok' => false, 'body' => [], 'rows' => [], 'error' => 'fault', 'status' => 400, 'halt' => false]];
        }
    };
    $client->payments['1'] = ['Id' => '1', 'Line' => [['LinkedTxn' => [['TxnType' => 'Invoice', 'TxnId' => '10']]]]];

    expect(qprRead($client, qprConnection(), ['1', '2']))->toBe([])
        ->and($client->calls)->toBe(['batch']);
});

test('one payment is read on its own, and a missing one is null', function () {
    $client                = new FakeQuickBooks();
    $client->payments['1'] = ['Id' => '1', 'Line' => [['LinkedTxn' => [['TxnType' => 'Invoice', 'TxnId' => '10']]]]];

    expect(qprRead($client, qprConnection(), ['1']))->toBe(['1' => ['10']])
        ->and(qprRead($client, qprConnection(), ['7']))->toBe(['7' => null])
        ->and($client->calls)->not->toContain('batch');
});

test('a connection for another realm, or one that needs reconnecting, reads nothing', function () {
    $client                = new FakeQuickBooks();
    $client->payments['1'] = ['Id' => '1', 'Line' => [['LinkedTxn' => [['TxnType' => 'Invoice', 'TxnId' => '10']]]]];

    expect(qprRead($client, qprConnection(), ['1'], 'realm-2'))->toBe([])
        ->and(qprRead($client, ['realm_id' => 'realm-1', 'needs_reauth' => true], ['1']))->toBe([])
        ->and(qprRead($client, null, ['1']))->toBe([])
        ->and($client->calls)->toBe([]);
});
