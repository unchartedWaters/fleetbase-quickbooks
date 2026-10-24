<?php

namespace Fleetbase\Quickbooks\Tests\Support;

use Fleetbase\Quickbooks\Services\QuickBooksClient;
use Fleetbase\Quickbooks\Services\SyncEngine;
use Fleetbase\Quickbooks\Services\SyncLedger;
use Fleetbase\Quickbooks\Support\BackoffPolicy;
use Fleetbase\Quickbooks\Support\CustomerMapper;
use Fleetbase\Quickbooks\Support\InvoiceMapper;
use Fleetbase\Quickbooks\Support\WalletMapper;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;

/**
 * One scheduled run that creates new customers in QuickBooks from the given pending rows, and
 * the requestid of each write it posts. The bodies are the same on every run.
 */
class CustomerCreateRun
{
    /**
     * @param array<string, string> $rows pending row uuid ('' for none) by customer uuid
     *
     * @return array<int, string> the requestid of each POST, in order
     */
    public static function requestIds(array $rows): array
    {
        $ids = [];
        Http::fake(function (Request $request) use (&$ids) {
            if ($request->method() === 'GET') {
                return Http::response(['QueryResponse' => []], 200);
            }
            $answers = [];
            $writes  = str_ends_with((string) parse_url($request->url(), PHP_URL_PATH), '/batch') === false;
            foreach ($request->data()['BatchItemRequest'] ?? [] as $index => $item) {
                $writes    = $writes === true || isset($item['Query']) === false;
                $answers[] = isset($item['Query']) === true
                    ? ['bId' => $item['bId'], 'QueryResponse' => []]
                    : ['bId' => $item['bId'], 'Customer' => ['Id' => (string) (70 + $index), 'SyncToken' => '0']];
            }
            if ($writes === true) {
                parse_str((string) parse_url($request->url(), PHP_URL_QUERY), $query);
                $ids[] = (string) ($query['requestid'] ?? '');
            }

            return Http::response(['Customer' => ['Id' => '77', 'SyncToken' => '0'], 'BatchItemResponse' => $answers]);
        });
        $engine = new SyncEngine(
            new QuickBooksClient(),
            new CustomerMapper(),
            new InvoiceMapper(),
            new WalletMapper(),
            new BackoffPolicy(fn (int $wait): int => $wait)
        );
        $engine->runScheduled(self::ledger($rows), 'company-uuid', qbSettings(), 1_800_000_000);

        return $ids;
    }

    /**
     * @param array<string, string> $rows
     */
    private static function ledger(array $rows): SyncLedger
    {
        $ledger                              = new SyncLedger();
        $ledger->connections['company-uuid'] = [
            'company_uuid'    => 'company-uuid',
            'realm_id'        => 'realm-1',
            'access_token'    => 'token',
            'environment'     => 'sandbox',
            'needs_reauth'    => false,
            'home_currency'   => 'USD',
            'default_item_id' => 'item-1',
        ];
        foreach ($rows as $customer => $rowUuid) {
            $ledger->customers[$customer] = [
                'uuid'         => $customer,
                'company_uuid' => 'company-uuid',
                'name'         => 'Customer ' . $customer,
                'email'        => $customer . '@example.test',
            ];
            $row = [
                'company_uuid' => 'company-uuid',
                'local_type'   => 'customer',
                'local_uuid'   => $customer,
                'status'       => 'pending',
                'attempts'     => 0,
            ];
            if ($rowUuid !== '') {
                $row['uuid'] = $rowUuid;
            }
            $ledger->pending[] = $row;
        }

        return $ledger;
    }
}
