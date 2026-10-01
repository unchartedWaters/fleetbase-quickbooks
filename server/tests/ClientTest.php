<?php

use Fleetbase\Quickbooks\Services\QuickBooksClient;
use Fleetbase\Quickbooks\Support\QuickBooksException;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;

test('the home currency is read from preferences currency prefs', function () {
    Http::fake([
        'sandbox-quickbooks.api.intuit.com/*' => Http::response([
            'Preferences' => ['CurrencyPrefs' => ['MultiCurrencyEnabled' => false, 'HomeCurrency' => ['value' => 'USD']]],
        ], 200),
    ]);

    expect((new QuickBooksClient())->homeCurrency(clientConnection()))->toBe('USD');
    Http::assertSent(fn (Request $request) => str_contains($request->url(), '/v3/company/realm-1/preferences'));
});

test('a missing home currency preference is unknown rather than the country', function () {
    Http::fake([
        'sandbox-quickbooks.api.intuit.com/*' => Http::response(['Preferences' => ['CurrencyPrefs' => []]], 200),
    ]);

    expect((new QuickBooksClient())->homeCurrency(clientConnection()))->toBeNull();
});

test('custom transaction numbers are read from sales form preferences once per company', function () {
    Http::fake([
        'sandbox-quickbooks.api.intuit.com/*' => Http::sequence()
            ->push(['Preferences' => ['SalesFormsPrefs' => ['CustomTxnNumbers' => true], 'CurrencyPrefs' => ['HomeCurrency' => ['value' => 'USD']]]], 200)
            ->push(['Preferences' => ['SalesFormsPrefs' => []]], 200),
    ]);

    $client = new QuickBooksClient();
    expect($client->customTxnNumbers(clientConnection()))->toBeTrue()
        ->and($client->homeCurrency(clientConnection()))->toBe('USD')
        ->and($client->customTxnNumbers(clientConnection()))->toBeTrue()
        ->and((new QuickBooksClient())->customTxnNumbers(clientConnection()))->toBeFalse();
    Http::assertSentCount(2);
});

test('the fleetbase service item is created against an income account', function () {
    Http::fake(function (Request $request) {
        $url = urldecode($request->url());
        if (str_contains($url, 'from Item')) {
            return Http::response(['QueryResponse' => []], 200);
        }
        if (str_contains($url, 'from Account')) {
            return Http::response(['QueryResponse' => ['Account' => [['Id' => '79', 'AccountType' => 'Income']]]], 200);
        }

        return Http::response(['Item' => ['Id' => '12']], 200);
    });

    expect((new QuickBooksClient())->ensureServiceItem(clientConnection()))->toBe('12');
    Http::assertSent(fn (Request $request) => str_contains(urldecode($request->url()), "AccountType = 'Income' maxresults 1"));
    Http::assertSent(fn (Request $request) => $request->method() === 'POST'
        && $request['Type'] === 'NonInventory'
        && $request['IncomeAccountRef'] === ['value' => '79']);
});

test('no service item is created when quickbooks has no income account', function () {
    Http::fake([
        'sandbox-quickbooks.api.intuit.com/*' => Http::response(['QueryResponse' => []], 200),
    ]);

    expect((new QuickBooksClient())->ensureServiceItem(clientConnection()))->toBe('');
    Http::assertNotSent(fn (Request $request) => $request->method() === 'POST');
});

test('a query value with a backslash or quote does not close the string', function () {
    $raw     = "O'Brien\\path";
    $escaped = QuickBooksClient::escapeQuery($raw);

    expect($escaped)->toBe("O\\'Brien\\\\path");

    $wrapped = "'" . $escaped . "'";
    expect(preg_match("/^'(?:\\\\'|\\\\\\\\|[^'])*'$/", $wrapped))->toBe(1);

    Http::fake([
        'sandbox-quickbooks.api.intuit.com/*' => Http::response(['QueryResponse' => ['Customer' => []]], 200),
    ]);

    (new QuickBooksClient())->findCustomerByDisplayName(clientConnection(), $raw);

    Http::assertSent(function (Request $request) use ($escaped) {
        $url = urldecode($request->url());

        return str_contains($url, "DisplayName = '" . $escaped . "'")
            && !str_contains($url, "DisplayName = 'O'Brien");
    });
});

test('two writes use one batch request and a failed item does not replay the success', function () {
    Http::fake([
        'sandbox-quickbooks.api.intuit.com/*' => Http::response([
            'BatchItemResponse' => [
                ['bId' => 'a', 'Customer' => ['Id' => '1', 'SyncToken' => '0', 'DisplayName' => 'Ada']],
                ['bId' => 'b', 'Fault' => ['Error' => [['Message' => 'Duplicate', 'Detail' => 'The name supplied already exists.', 'code' => '6240']]]],
            ],
        ], 200),
    ]);

    $results = (new QuickBooksClient())->batch(clientConnection(), [
        ['bId' => 'a', 'operation' => 'create', 'entity' => 'Customer', 'payload' => ['DisplayName' => 'Ada']],
        ['bId' => 'b', 'operation' => 'create', 'entity' => 'Customer', 'payload' => ['DisplayName' => 'Bea']],
    ]);

    expect($results['a']['ok'])->toBeTrue()
        ->and($results['a']['body']['Id'])->toBe('1')
        ->and($results['b']['ok'])->toBeFalse()
        ->and($results['b']['error'])->toBe('The name supplied already exists.');
    Http::assertSentCount(1);
    Http::assertSent(fn (Request $request) => str_contains($request->url(), '/v3/company/realm-1/batch')
        && !str_contains($request->url(), '/customer'));
});

test('a payment lookup follows the target invoice link without scanning customer history', function () {
    $match = ['Id' => 'pay-9', 'Line' => [['Amount' => '4.00', 'LinkedTxn' => [['TxnId' => 'inv-9', 'TxnType' => 'Invoice']]]]];
    Http::fake(function (Request $request) use ($match) {
        $url = urldecode($request->url());
        if (str_contains($url, '/invoice/inv-9')) {
            return Http::response(['Invoice' => [
                'Id' => 'inv-9',
                'LinkedTxn' => [['TxnId' => 'pay-9', 'TxnType' => 'Payment']],
            ]], 200);
        }
        if (str_contains($url, '/payment/pay-9')) {
            return Http::response(['Payment' => $match], 200);
        }

        return Http::response([], 500);
    });

    $payment = (new QuickBooksClient())->findPaymentForInvoice(clientConnection(), 'cust-1', 'inv-9');

    expect($payment['Id'] ?? null)->toBe('pay-9');
    Http::assertSentCount(2);
    Http::assertNotSent(fn (Request $request) => str_contains(urldecode($request->url()), 'CustomerRef'));
});

test('targeted payment lookups batch invoice and payment ids in chunks of 30', function () {
    Http::fake(function (Request $request) {
        $responses = [];
        foreach ($request->data()['BatchItemRequest'] ?? [] as $item) {
            $query = (string) ($item['Query'] ?? '');
            preg_match_all("/'([^']+)'/", $query, $matches);
            $rows = [];
            foreach ($matches[1] as $id) {
                $rows[] = str_contains($query, 'from Invoice')
                    ? ['Id' => $id, 'LinkedTxn' => [['TxnId' => 'pay-' . $id, 'TxnType' => 'Payment']]]
                    : ['Id' => $id, 'Line' => []];
            }
            $entity = str_contains($query, 'from Invoice') ? 'Invoice' : 'Payment';
            $responses[] = [
                'bId' => $item['bId'],
                'QueryResponse' => [$entity => $rows],
            ];
        }

        return Http::response(['BatchItemResponse' => $responses], 200);
    });

    $targets = [];
    for ($i = 1; $i <= 31; $i++) {
        $targets['inv-' . $i] = 'cust-' . $i;
    }
    $payments = (new QuickBooksClient())->findPaymentsForCustomers(clientConnection(), $targets, true);

    expect($payments)->toHaveCount(31);
    Http::assertSentCount(2);
    Http::assertSent(function (Request $request) {
        foreach ($request->data()['BatchItemRequest'] ?? [] as $item) {
            preg_match_all("/'[^']+'/", (string) ($item['Query'] ?? ''), $matches);
            if (count($matches[0]) > QuickBooksClient::BATCH_LIMIT) {
                return false;
            }
        }

        return true;
    });
});

test('listing invoices pages past the 1000 row query cap', function () {
    $full = [];
    for ($i = 1; $i <= 1000; $i++) {
        $full[] = ['Id' => (string) $i];
    }
    Http::fake(function (Request $request) use ($full) {
        $url = urldecode($request->url());
        if (str_contains($url, 'startposition 1 ')) {
            return Http::response(['QueryResponse' => ['Invoice' => $full]], 200);
        }
        if (str_contains($url, 'startposition 1001 ')) {
            return Http::response(['QueryResponse' => ['Invoice' => [['Id' => '1001']]]], 200);
        }

        return Http::response(['QueryResponse' => []], 200);
    });

    $rows = (new QuickBooksClient())->listInvoices(clientConnection());

    expect($rows)->toHaveCount(1001)
        ->and($rows[1000]['Id'])->toBe('1001');
    Http::assertSentCount(2);
});

test('a failed request includes the quickbooks fault detail', function () {
    Http::fake([
        'sandbox-quickbooks.api.intuit.com/*' => Http::response([
            'Fault' => ['Error' => [['Message' => 'Duplicate Name Exists Error', 'Detail' => 'The name supplied already exists.', 'code' => '6240']], 'type' => 'ValidationFault'],
        ], 400),
    ]);

    try {
        (new QuickBooksClient())->createCustomer(clientConnection(), ['DisplayName' => 'Ada']);
        expect(false)->toBeTrue();
    } catch (QuickBooksException $exception) {
        expect($exception->status)->toBe(400)
            ->and($exception->getMessage())->toBe('QuickBooks request failed with status 400: The name supplied already exists.');
    }
});

test('a 400 with fault code 610 on a read is treated as not found', function () {
    Http::fake([
        'sandbox-quickbooks.api.intuit.com/*' => Http::response([
            'Fault' => ['Error' => [['Message' => 'Object Not Found', 'Detail' => 'Object Not Found : Something you\'re trying to use has been made inactive.', 'code' => '610']], 'type' => 'ValidationFault'],
        ], 400),
    ]);

    expect((new QuickBooksClient())->getInvoice(clientConnection(), '42'))->toBeNull();
});

test('any other 400 on a read still throws', function () {
    Http::fake([
        'sandbox-quickbooks.api.intuit.com/*' => Http::response([
            'Fault' => ['Error' => [['Message' => 'Invalid Reference Id', 'Detail' => 'Invalid Reference Id', 'code' => '2500']], 'type' => 'ValidationFault'],
        ], 400),
    ]);

    try {
        (new QuickBooksClient())->getInvoice(clientConnection(), '42');
        expect(false)->toBeTrue();
    } catch (QuickBooksException $exception) {
        expect($exception->status)->toBe(400)
            ->and($exception->faultCode)->toBe('2500');
    }
});

test('one new invoice number is a latest query and one existence query', function () {
    Http::fake(function (Request $request) {
        $url = urldecode($request->url());
        if (str_contains($url, 'orderby MetaData.CreateTime desc')) {
            return Http::response(['QueryResponse' => ['Invoice' => [['DocNumber' => 'INV-009']]]], 200);
        }

        return Http::response(['QueryResponse' => ['Invoice' => []]], 200);
    });

    expect((new QuickBooksClient())->nextInvoiceDocNumber(clientConnection()))->toBe('INV-010');
    Http::assertSentCount(2);
    Http::assertNotSent(fn (Request $request) => str_contains($request->url(), '/batch'));
    Http::assertSent(fn (Request $request) => str_contains(urldecode($request->url()), "DocNumber = 'INV-010'"));
});

test('a block of invoice numbers uses one latest query and one batched existence check', function () {
    Http::fake(function (Request $request) {
        $url = urldecode($request->url());
        if ($request->method() === 'GET' && str_contains($url, 'orderby MetaData.CreateTime desc maxresults 1')) {
            return Http::response(['QueryResponse' => ['Invoice' => [['DocNumber' => 'INV-009']]]], 200);
        }
        if ($request->method() === 'POST' && str_contains($url, '/batch')) {
            return Http::response([
                'BatchItemResponse' => [[
                    'bId'           => 'doc-0',
                    'QueryResponse' => ['Invoice' => [['Id' => 'taken', 'DocNumber' => 'INV-010']]],
                ]],
            ], 200);
        }

        return Http::response(['QueryResponse' => ['Invoice' => [['Id' => 'x', 'DocNumber' => 'SHOULD-NOT']]]], 200);
    });

    $numbers = (new QuickBooksClient())->nextInvoiceDocNumbers(clientConnection(), 2);

    expect($numbers)->toBe(['INV-011', 'INV-012']);
    Http::assertSentCount(2);
    Http::assertNotSent(fn (Request $request) => str_contains(urldecode($request->url()), "DocNumber = '"));
    Http::assertSent(function (Request $request) {
        if (!str_contains($request->url(), '/batch')) {
            return false;
        }
        $items = $request->data()['BatchItemRequest'] ?? [];
        $query = (string) ($items[0]['Query'] ?? '');

        return count($items) === 1
            && str_contains($query, 'DocNumber IN (')
            && str_contains($query, "'INV-010'")
            && str_contains($query, "'INV-011'")
            && str_contains($query, "'INV-016'");
    });
});

test('more than 30 invoice number candidates are checked in batch chunks of 30', function () {
    Http::fake(function (Request $request) {
        $url = urldecode($request->url());
        if ($request->method() === 'GET' && str_contains($url, 'orderby MetaData.CreateTime')) {
            return Http::response(['QueryResponse' => ['Invoice' => [['DocNumber' => '1000']]]], 200);
        }

        return Http::response(['BatchItemResponse' => [
            ['bId' => 'doc-0', 'QueryResponse' => ['Invoice' => []]],
            ['bId' => 'doc-1', 'QueryResponse' => ['Invoice' => []]],
        ]], 200);
    });

    $numbers = (new QuickBooksClient())->nextInvoiceDocNumbers(clientConnection(), 30);

    expect($numbers)->toHaveCount(30)
        ->and($numbers[0])->toBe('1001')
        ->and($numbers[29])->toBe('1030');
    Http::assertSentCount(2);
    Http::assertSent(function (Request $request) {
        if (!str_contains($request->url(), '/batch')) {
            return false;
        }
        $items = $request->data()['BatchItemRequest'] ?? [];
        if (count($items) !== 2) {
            return false;
        }
        preg_match_all("/'[^']+'/", (string) ($items[0]['Query'] ?? ''), $first);
        preg_match_all("/'[^']+'/", (string) ($items[1]['Query'] ?? ''), $second);

        return count($first[0]) === 30 && count($second[0]) === 5;
    });
});

test('update writes are chunked at 20 and other batch calls stay at 30', function () {
    $updates = [];
    for ($i = 1; $i <= 21; $i++) {
        $updates[] = ['bId' => 'u-' . $i, 'operation' => 'update', 'entity' => 'Customer', 'payload' => ['Id' => (string) $i]];
    }
    $creates = [];
    for ($i = 1; $i <= 31; $i++) {
        $creates[] = ['bId' => 'c-' . $i, 'operation' => 'create', 'entity' => 'Customer', 'payload' => []];
    }
    $queries = [];
    for ($i = 1; $i <= 31; $i++) {
        $queries[] = ['bId' => 'q-' . $i, 'query' => "select * from Customer where Id = '" . $i . "'"];
    }
    $mixed = array_merge(
        array_slice($creates, 0, 5),
        array_slice($updates, 0, 21),
        [['bId' => 'v-1', 'operation' => 'void', 'entity' => 'Invoice', 'payload' => ['Id' => '9']]]
    );

    expect(QuickBooksClient::UPDATE_BATCH_SIZE)->toBe(20)
        ->and(array_map('count', QuickBooksClient::batchChunks($updates)))->toBe([20, 1])
        ->and(array_map('count', QuickBooksClient::batchChunks($creates)))->toBe([30, 1])
        ->and(array_map('count', QuickBooksClient::batchChunks($queries)))->toBe([30, 1])
        ->and(array_map('count', QuickBooksClient::batchChunks($mixed)))->toBe([5, 20, 1, 1])
        ->and(QuickBooksClient::batchChunks($mixed)[1][0]['operation'])->toBe('update')
        ->and(QuickBooksClient::batchChunks($mixed)[3][0]['operation'])->toBe('void');
});

function clientConnection(): array
{
    return ['company_uuid' => 'company-uuid', 'realm_id' => 'realm-1', 'access_token' => 'token', 'environment' => 'sandbox'];
}
