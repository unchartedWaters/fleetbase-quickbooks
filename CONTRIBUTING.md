# Contributing

This package syncs Fleetbase customers, invoices, payments, and wallets (QuickBooks accounts) with QuickBooks Online.

The package git remote is still https://github.com/fleetbase/starter-extension.

## Setup

- PHP 8.2 or newer
- Node.js 18 or newer
- Composer dependencies: `composer install` in this package
- Node dependencies: `pnpm install` in this package

## Behavior

- Activity lists batch counts. Errors are shown on the line under the row.
- The Ledger dashboard widget is QuickBooks Sync. Its Sync now button requires a connection and a configured Client ID, Redirect URI, and Client secret.
- `/quickbooks/connection` redirects to `/quickbooks` (Connection).
- Connect without Client ID, Client secret, and Redirect URI returns HTTP 422 and does not open Intuit.
- Sync now and Reconcile, when QuickBooks is not connected, return HTTP 422 and write an activity row.
- Import customers without a connection returns HTTP 422 with a message to connect from Connection, and writes a skipped activity row.
- The callback path is `/quickbooks/int/v1/oauth/callback`. The Redirect URI is that path on the Fleetbase API host, for example `https://{{fleetbase.url}}/quickbooks/int/v1/oauth/callback`. Sandbox uses Development keys and `https://sandbox-quickbooks.api.intuit.com`. Production uses Production keys and `https://quickbooks.api.intuit.com`. After the callback, the browser returns to `CONSOLE_HOST` plus `/quickbooks?oauth_state=...`. `CONSOLE_HOST` (or `QUICKBOOKS_CONSOLE_HOST`, checked first) must be set so QuickBooks can return to the console; `fleetbase.console.host` is used when neither is set. An `http://` or `https://` prefix is kept, and `https://` is added when there is none. The console then finishes the connection with `POST /quickbooks/int/v1/oauth/complete`, which checks the signed-in user and organization.
- A failed callback returns to `/quickbooks?error=...` with `cancelled` (cancelled on Intuit), `state` (missing, expired, or used OAuth state), or `failed` (any other Intuit error). The console maps each code to a fixed message.
- Each organization connects from Ledger → Settings → QuickBooks settings → Connection, which is also `/quickbooks`. Turn on Override and save that organization's Client ID, Client secret, Redirect URI, and webhook verifier. There is no Admin QuickBooks settings screen. A previously stored system default or the environment variables are used when Override is off. `scope=admin` is not a settings screen.
- Tokens are refreshed before each sync, import, or connection test when they expire within 5 minutes. If refresh fails, the connection is marked as needing reconnection. During a sync or import, a skipped activity row says to connect from Connection. During Test connection, the console shows the message.
- A 401 from QuickBooks during a sync stops the batch and the console asks to connect QuickBooks again.
- An import does not run while a sync is running.
- Home currency comes from QuickBooks Preferences. Test connection also reads Preferences.
- Each category on Connection has a Primary of Fleetbase or QuickBooks, which decides which system wins when records differ and which system supplies identifiers. The schedule runs only when Schedule → Enable is on. Sync now and Reconcile still run when that switch is off.

## Tests

```bash
composer test:unit
composer test:types
```

`composer test:unit` runs Pest from `server/tests`. `composer test:types` runs PHPStan on `server/src`.

Ember tests (`pnpm test:ember`) need Chrome. Skip them when Chrome is not installed.

## Lint

```bash
composer test:lint
pnpm lint
```
