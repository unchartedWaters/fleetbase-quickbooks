# Contributing

This package syncs Fleetbase customers, invoices, payments, and wallets (QuickBooks accounts) with QuickBooks Online.

## Setup

- PHP 8.2. This is the supported version, it matches Fleetbase, and CI runs it
- Node.js 22 or newer (CI runs the current Node.js LTS)
- Composer dependencies: `composer install` in this package
- Node dependencies: `pnpm install --frozen-lockfile` in this package

## Behavior

- Activity lists batch counts. Errors are shown on the line under the row.
- Server messages come from `translations/en-us.yaml`, the same file the
  console uses. Keys are under `quickbooks.messages` and
  `quickbooks.validation`. API messages use the signed-in user's console
  language. Activity errors are stored as keys and shown in the reader's
  language. Rows saved before this change keep their English text.
- The Ledger dashboard widget is QuickBooks Sync. Its Sync now button requires a connection and a saved Client ID and Client secret. A public https redirect is required to connect, not to run Sync now.
- Organization settings lists QuickBooks Setup
  (`/settings/quickbooks-setup?view=index`) and QuickBooks Activity
  (`/settings/quickbooks-activity?view=index`) with Organization, Two Factor,
  and Notifications. The QuickBooks header item opens QuickBooks Setup. Engine
  routes `connection`, `activity`, and `actions` redirect to
  `console.quickbooks.settings`.
- Connect without Client ID, Client secret, and Redirect URI returns HTTP 422 and does not open Intuit.
- The Connection card on QuickBooks Setup shows only the actions that apply:
  Connect when not connected or when QuickBooks must be connected again,
  Sync now when connected, and Disconnect when a company is saved. An action
  the user lacks permission for stays visible but disabled. Reset to global
  defaults sits next to Save Changes in the page header.
- Sync now with no QuickBooks connection returns HTTP 422 and does not save an activity row. Reconcile uses that same gate. A stored realm that needs reconnect returns HTTP 422 and saves a skipped activity row.
- Import customers with no connection returns HTTP 422 with a message to connect
  from QuickBooks Setup, and does not save an activity row. A stored realm that
  needs reconnect saves a skipped activity row.
- The callback path is `/quickbooks/int/v1/oauth/callback`. The Redirect URI is
  that path on the Fleetbase API host, for example
  `https://{{fleetbase.url}}/quickbooks/int/v1/oauth/callback`. Sandbox uses
  Development keys and `https://sandbox-quickbooks.api.intuit.com`. Production
  uses Production keys and `https://quickbooks.api.intuit.com`. After the
  callback, the browser returns to `CONSOLE_HOST` plus
  `/quickbooks?oauth_state=...`. `CONSOLE_HOST` (or `QUICKBOOKS_CONSOLE_HOST`,
  checked first) must be set so QuickBooks can return to the console;
  `fleetbase.console.host` counts only when it is set to something other than
  its default `fleetbase.io`, so set `CONSOLE_HOST` (or
  `QUICKBOOKS_CONSOLE_HOST`) explicitly. An `http://` or `https://` prefix is
  kept, and `https://` is added when there is none. The console then finishes
  the connection with `POST /quickbooks/int/v1/oauth/complete`, which checks the
  signed-in user and organization.
- A failed callback returns to `/quickbooks?error=...` with `cancelled` (cancelled on Intuit), `state` (missing, expired, or used OAuth state), or `failed` (any other Intuit error). The console maps each code to a fixed message.
- Global settings (Client ID, Client secret, Redirect URI, environment, webhook
  verifier, public URLs) are edited only by an installation administrator on
  Admin → QuickBooks → Settings, with `scope=admin` on the system settings rows.
  The sync options on that screen are only a template. Changing them does not
  change any organization's sync settings, and a global save queues nothing for
  organizations. A blank system field stays blank. QuickBooks Setup uses
  `scope=company`: it shows the connection controls and the organization's own
  sync options, and saves only sync options (with `quickbooks update settings`).
  Sending `reset` copies the current template over the organization's settings
  once. An `auth` or unknown key at company scope returns HTTP 422. Older
  organization rows without the `scope: organization` marker are ignored.
  Existing installs re-seed permissions with
  `php artisan fleetbase:create-permissions`. Environment may fall back to
  `QUICKBOOKS_ENVIRONMENT`, which defaults to `production`.
- Tokens are refreshed before each sync or import when they expire within 5
  minutes (the admin-only `POST connection/test` API endpoint does the same; the
  console has no Test connection button). Reconnection is requested only when
  Intuit refuses the refresh token (`invalid_grant`). Other refresh failures
  keep the old tokens, record a refresh error, and leave the rows pending for a
  later run. During a sync or import, a skipped activity row says to connect
  from QuickBooks Setup.
- A 401 from QuickBooks during a sync first refreshes the token and retries the
  call once. The batch stops and the console asks to connect again only when
  Intuit refuses the refresh token (`invalid_grant`) or the new token is also
  refused.
- Turning an entity on or connecting queues the existing records in a background
  job, not in the request. Queue workers must be running. Changing the global
  sync template queues nothing.
- An organization gets its own complete copy of the sync settings when it
  connects (before its existing records are queued), when it saves its settings,
  or when it uses Reset to global defaults (a one-time copy of the current
  template). Reconnecting keeps the existing copy. There is no override toggle
  and no per-field inheritance. Before an organization has a copy, its settings
  screen shows the current global defaults as starting values.
- On upgrade, run `php artisan migrate`. The snapshot migration gives every
  existing organization (with a QuickBooks connection or an organization
  settings row) a copy of its current effective settings, so nothing changes at
  upgrade. The same command runs the earlier `quickbooks_links` index migration.
- An import does not run while a sync is running.
- Home currency comes from QuickBooks Preferences.
- QuickBooks Setup → Data Resolution lists Customers, Invoices, Payments, and
  Accounts / Wallets. Each has a switch, then Primary (Fleetbase or QuickBooks)
  and Sync direction. Primary decides which system wins when records differ and
  which system supplies identifiers. The Schedule panel holds Sync frequency and
  the retry fields. There is no Enable schedule control, and the form does not
  send `sync.enabled`. A stored `enabled` flag does not stop the scheduler. The
  schedule runs when a connection does not need reconnect, QuickBooks is not
  rate limited, and either Sync frequency has elapsed with at least one due
  pending row, or at least 20 pending rows are already due. The customer catalog
  can also start a minute, and it is skipped when Customers is off. Sync now
  still runs before that interval. One flagged row does not start the next
  minute. Sync now processes one pending page and does not drain the rest of the
  queue.
- Deletes: a Fleetbase customer or wallet delete makes the linked QuickBooks
  record inactive, and a deleted invoice line is removed from the QuickBooks
  invoice (item `meta` keeps each line's QuickBooks id). A QuickBooks delete
  that the sync settings do not apply writes a skipped Activity row. A
  QuickBooks merge moves the link to the surviving record. A deleted QuickBooks
  payment re-reads the invoice balance for its amount paid. See
  [docs/SETUP.md](docs/SETUP.md#webhooks).

## Tests

PHP 8.2 is the supported and CI version, matching Fleetbase.

Back end: run `composer install`, then `composer test`. `composer test` runs lint, PHPStan, and Pest. The parts can run alone:

```bash
composer test:unit
composer test:types
```

`composer test:unit` runs Pest from `server/tests`. `composer test:types` runs PHPStan on `server/src`.

`composer test:sandbox` runs the live tests in
`server/tests/SandboxLiveTest.php` against a real QuickBooks sandbox company.
`composer test:unit` and CI leave them out. They need four environment
variables: `QB_SANDBOX_CLIENT_ID` and `QB_SANDBOX_CLIENT_SECRET` from the
sandbox app, and `QB_SANDBOX_REFRESH_TOKEN` and `QB_SANDBOX_REALM_ID` from
Intuit's OAuth 2.0 Playground for that app. The run fails and names any that
are missing. The machine must reach `oauth.platform.intuit.com`,
`sandbox-quickbooks.api.intuit.com`, `appcenter.intuit.com` and
`accounts.intuit.com`.

The connect test follows the link the Connect button opens as far as Intuit's
sign-in page. Intuit checks the app's keys and redirect URL only after someone
signs in, so the test cannot catch a wrong key or an unregistered redirect URL.

Each run adds its own customers, invoices, payments and accounts to the
sandbox company, so an empty company works. Turn off automated sales tax there,
or the invoice totals will not match. Intuit replaces the refresh token about
once a day and the old one then stops working. When a run fails with
`invalid_grant`, get a new refresh token from the Playground.

Front end: run `pnpm install --frozen-lockfile`, then `pnpm lint` and `pnpm test:ember`. Ember tests need Chrome. Skip them when Chrome is not installed.

## Lint

```bash
composer test:lint
pnpm lint
```
