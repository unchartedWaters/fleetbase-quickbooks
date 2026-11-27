<?php

use Fleetbase\Quickbooks\Services\ConnectionProbe;
use Fleetbase\Quickbooks\Tests\Support\CompanyFixtures;
use Fleetbase\Quickbooks\Tests\Support\CountingCompanyClient;
use Illuminate\Support\Facades\Cache;

beforeEach(function () {
    Cache::flush();
});

test('the company name and home currency come from QuickBooks and are cached', function () {
    [$details, $client] = CompanyFixtures::details();
    $row                = CompanyFixtures::row();

    $first  = $details->for($row);
    $second = $details->for($row);

    expect($first)->toBe(['company_name' => 'Craig Landscaping', 'home_currency' => 'CAD'])
        ->and($second)->toBe($first)
        ->and($client->reads)->toBe(1);
});

test('another realm for the same organization is read again', function () {
    [$details, $client] = CompanyFixtures::details();

    $details->for(CompanyFixtures::row());
    $details->for(CompanyFixtures::row(['realm_id' => 'realm-2']));

    expect($client->reads)->toBe(2);
});

test('a failed read is remembered briefly so each page load does not call QuickBooks', function () {
    [$details, $client] = CompanyFixtures::details();
    $client->fail       = true;
    $row                = CompanyFixtures::row();

    $first = $details->for($row);
    $details->for($row);

    expect($first)->toBe(['company_name' => null, 'home_currency' => null])
        ->and($client->reads)->toBe(1);
});

test('a connection that needs to be connected again is not read', function () {
    [$details, $client] = CompanyFixtures::details();

    $shown = $details->for(CompanyFixtures::row(['needs_reauth' => true]));
    $none  = $details->for(CompanyFixtures::row(['realm_id' => '']));

    expect($shown['company_name'])->toBeNull()
        ->and($none['company_name'])->toBeNull()
        ->and($client->reads)->toBe(0);
});

test('the organization currency is upper case, and empty when unknown', function (mixed $stored, ?string $expected) {
    [$details]         = CompanyFixtures::details();
    $details->currency = $stored;

    expect($details->organizationCurrency('company-1'))->toBe($expected);
})->with([
    'lower case' => ['cad', 'CAD'],
    'padded'     => [' usd ', 'USD'],
    'empty'      => ['', null],
    'missing'    => [null, null],
]);

test('an unreadable organization currency is empty instead of failing the card', function () {
    $details = new Fleetbase\Quickbooks\Services\CompanyDetails(new ConnectionProbe(new CountingCompanyClient()));

    expect($details->organizationCurrency('company-1'))->toBeNull();
});
