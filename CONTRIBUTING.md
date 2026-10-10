# Contributing

This package syncs Fleetbase customers, invoices, payments, and wallets (QuickBooks accounts) with QuickBooks Online.

The package git remote is still https://github.com/fleetbase/starter-extension.

## Setup

- PHP 8.2. This is the supported version, it matches Fleetbase, and CI runs it
- Node.js 18 or newer
- Composer dependencies: `composer install` in this package
- Node dependencies: `pnpm install --frozen-lockfile` in this package

## Behavior

- Activity lists batch counts. Errors are shown on the line under the row.
- The Ledger dashboard widget is QuickBooks Sync. Its Sync now button requires a connection and a saved Client ID and Client secret. A public https redirect is required to connect, not to run Sync now.
- Organization settings lists Quickbooks Setup (`/settings/quickbooks-setup?view=index`) and Quickbooks Activity (`/settings/quickbooks-activity?view=index`) with Organization, Two Factor, and Notifications. The QuickBooks header item opens Quickbooks Setup. Engine routes `connection`, `activity`, and `actions` redirect to `console.quickbooks.settings`.
- Connect without Client ID, Client secret, and Redirect URI returns HTTP 422 and does not open Intuit.
- Sync now with no QuickBooks connection returns HTTP 422 and does not save an activity row. Reconcile uses that same gate. A stored realm that needs reconnect returns HTTP 422 and saves a skipped activity row.
- Import customers with no connection returns HTTP 422 with a message to connect from Quickbooks Setup, and does not save an activity row. A stored realm that needs reconnect saves a skipped activity row.
- The callback path is `/quickbooks/int/v1/oauth/callback`. The Redirect URI is that path on the Fleetbase API host, for example `https://{{fleetbase.url}}/quickbooks/int/v1/oauth/callback`. Sandbox uses Development keys and `https://sandbox-quickbooks.api.intuit.com`. Production uses Production keys and `https://quickbooks.api.intuit.com`. After the callback, the browser returns to `CONSOLE_HOST` plus `/quickbooks?oauth_state=...`. `CONSOLE_HOST` (or `QUICKBOOKS_CONSOLE_HOST`, checked first) must be set so QuickBooks can return to the console; `fleetbase.console.host` is used when neither is set. An `http://` or `https://` prefix is kept, and `https://` is added when there is none. The console then finishes the connection with `POST /quickbooks/int/v1/oauth/complete`, which checks the signed-in user and organization.
- A failed callback returns to `/quickbooks?error=...` with `cancelled` (cancelled on Intuit), `state` (missing, expired, or used OAuth state), or `failed` (any other Intuit error). The console maps each code to a fixed message.
- Quickbooks Setup is install-wide. It loads and saves Client ID, Client secret, Redirect URI, the webhook verifier, and sync options with `scope=admin` on the system settings row. A blank system field stays blank. There is no Admin QuickBooks settings screen and no per-organization Override. Environment may fall back to `QUICKBOOKS_ENVIRONMENT`, which defaults to `production`.
- Tokens are refreshed before each sync, import, or connection test when they expire within 5 minutes. If refresh fails, the connection is marked as needing reconnection. During a sync or import, a skipped activity row says to connect from Quickbooks Setup.
- A 401 from QuickBooks during a sync stops the batch and the console asks to connect QuickBooks again.
- An import does not run while a sync is running.
- Home currency comes from QuickBooks Preferences.
- Quickbooks Setup → Data Resolution lists Customers, Invoices, Payments, and Accounts / Wallets. Each has a switch, then Primary (Fleetbase or QuickBooks) and Sync direction. Primary decides which system wins when records differ and which system supplies identifiers. The Schedule panel holds Sync Frequency and the retry fields. There is no Enable schedule control, and the form does not send `sync.enabled`. A stored `enabled` flag does not stop the scheduler. The schedule runs when a connection does not need reconnect, QuickBooks is not rate limited, and either Sync Frequency has elapsed with at least one due pending row, or at least 20 pending rows are already due. The customer catalog can also start a minute, and it is skipped when Customers is off. Sync now still runs before that interval. One flagged row does not start the next minute. Sync now processes one pending page and does not drain the rest of the queue.

## Tests

PHP 8.2 is the supported and CI version, matching Fleetbase.

Back end: run `composer install`, then `composer test`. `composer test` runs lint, PHPStan, and Pest. The parts can run alone:

```bash
composer test:unit
composer test:types
```

`composer test:unit` runs Pest from `server/tests`. `composer test:types` runs PHPStan on `server/src`.

Front end: run `pnpm install --frozen-lockfile`, then `pnpm lint` and `pnpm test:ember`. Ember tests need Chrome. Skip them when Chrome is not installed.

## Lint

```bash
composer test:lint
pnpm lint
```
