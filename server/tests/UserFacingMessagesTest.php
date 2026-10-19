<?php

use Fleetbase\Quickbooks\Auth\Schemas\Quickbooks;
use Fleetbase\Quickbooks\Jobs\ImportCustomers;
use Fleetbase\Quickbooks\Notifications\QuickbooksNeedsReauth;
use Fleetbase\Quickbooks\Services\BatchRunner;
use Fleetbase\Quickbooks\Services\ConnectionTokens;
use Fleetbase\Quickbooks\Services\SyncEngine;
use Fleetbase\Quickbooks\Services\TokenRefresher;

test('reconnect messages say where to connect again', function () {
    $reconnect = 'QuickBooks needs to be connected again. Choose Connect to QuickBooks on QuickBooks Setup.';

    expect(qbText(ConnectionTokens::REAUTH_MESSAGE))->toBe($reconnect)
        ->and(qbText(SyncEngine::TOKEN_REJECTED_MESSAGE))->toBe($reconnect)
        ->and(qbText(ImportCustomers::blockedMessage(['needs_reauth' => true])))
        ->toBe('QuickBooks needs to be connected again before import can continue. '
            . 'Choose Connect to QuickBooks on QuickBooks Setup.')
        ->and(qbText(ImportCustomers::blockedMessage(null)))->toBe('QuickBooks is not connected. '
            . 'Connect from QuickBooks Setup.');
});

test('token and lock messages are written for the people who read them', function () {
    expect(qbText(TokenRefresher::CREDENTIALS_MESSAGE))
        ->toBe("QuickBooks refused the app's Client ID or Client secret. "
            . 'Ask an installation administrator to check them in Admin → QuickBooks → Settings.')
        ->and(qbText(TokenRefresher::UNAVAILABLE_MESSAGE))->toBe('Fleetbase could not renew the QuickBooks sign-in. '
            . 'It tries again on the next sync.')
        ->and(qbText(ConnectionTokens::ALREADY_RUNNING))->toBe('This run was skipped because Fleetbase is already '
            . 'renewing the '
            . 'QuickBooks sign-in.')
        ->and(qbText(BatchRunner::LOCK_UNAVAILABLE))
        ->toBe("This run was skipped because the Fleetbase server's cache does not support locks. "
            . 'Ask an installation administrator to check the cache settings.');
});

test('the reauth notification keeps its name and says where to reconnect', function () {
    $notification = new QuickbooksNeedsReauth('company-uuid', 'realm-1');

    expect($notification->name)->toBe('QuickBooks needs reauthorization')
        ->and($notification->description)->toBe("Sent when QuickBooks no longer accepts an organization's connection "
            . 'and it must be connected again.')
        ->and($notification->toArray(new stdClass())['message'])
        ->toBe('QuickBooks needs to be connected again. Choose Connect to QuickBooks on QuickBooks Setup.');
});

test('the operator role description matches what its policy grants', function () {
    $roles = array_column((new Quickbooks())->roles, 'description', 'name');

    expect($roles['QuickBooks Operator'])
        ->toBe('Reconnects the QuickBooks company the organization already uses, disconnects, imports customers, '
            . 'views settings and sync activity, and runs Sync now. Cannot change settings or connect a different '
            . 'QuickBooks company.');
});

test('client and oauth messages keep their wording', function () {
    expect(qbText('quickbooks.messages.oauth-invalid-link'))
        ->toBe('This QuickBooks authorization link is not valid. Connect again from QuickBooks Setup.')
        ->and(qbText('quickbooks.messages.item-rejected'))->toBe('QuickBooks rejected this item.')
        ->and(qbText('quickbooks.messages.batch-item-no-entity'))->toBe('QuickBooks batch item had no entity.')
        ->and(qbText('quickbooks.messages.batch-item-missing'))
        ->toBe('QuickBooks batch response did not include this item.')
        ->and(qbText('quickbooks.messages.invoice-lookup-failed'))->toBe('QuickBooks invoice lookup failed.');
});
