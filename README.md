# QuickBooks

Fleetbase extension that syncs customers, invoices, payments, and wallets (QuickBooks accounts) with QuickBooks Online.

Planned repository: [unchartedWaters/fleetbase-quickbooks](https://github.com/unchartedWaters/fleetbase-quickbooks). The git remote in this tree is still `https://github.com/fleetbase/starter-extension.git` and has not been changed.

Package names:

| | Name |
| --- | --- |
| Composer | `unchartedwaters/quickbooks-api` |
| npm / Ember engine | `@unchartedwaters/quickbooks-engine` |
| PHP namespace | `Fleetbase\Quickbooks` |

Composer package names and npm scopes are lowercase, so those two fields use `unchartedwaters`. The GitHub organization is `unchartedWaters`. The PHP namespace stays `Fleetbase\Quickbooks`.

## What syncs

- **Customers** — name, email, phone, notes, and billing address.
- **Invoices** — number, dates, notes, currency, tax, total, and the sales-item lines Fleetbase understands (description, quantity, unit price, and amount). Draft invoices are sent after they leave draft. QuickBooks products, discounts, and other line types are not copied back.
- **Payments** — a payment for a paid Fleetbase invoice. Amount and date are compared when a payment already exists. The payload includes `TxnDate`. A QuickBooks payment that also applies to other invoices is left unchanged.
- **Wallets** — a QuickBooks account (`Other Current Asset`). Fleetbase sends the wallet public ID as `AcctNum` only when that ID is 7 characters or fewer. Longer public IDs, such as `wallet_` plus 10 characters, are not sent. If the link is lost, a unique account with the same name and currency is reattached instead of creating a duplicate. When QuickBooks is primary, the account name and `AcctNum` are copied onto the wallet.

Unknown QuickBooks invoices are not imported. An invoice is synced from QuickBooks only when this Fleetbase organization already has that invoice or a `quickbooks_links` row for it. The webhook event is still dispatched for those invoices.

Money is stored as integer minor units. QuickBooks major-unit amounts are converted with `Amounts::toMinorUnits` before they are saved, and a PHP float is rejected. Unit price on an invoice line is an integer minor-unit value. When it is sent to QuickBooks it is formatted as a decimal string, not a float.

## Settings

Settings are organization-only. There is no admin QuickBooks settings screen. A request with `scope=admin` returns HTTP 404. There is no shared system row and no environment-variable fallback for Client ID, Client secret, Redirect URI, or webhook verifier.

The menu group title is exactly **Quickbooks Settings**. Its items are **Connection**, **Actions**, and **Activity**. Ledger → Settings opens the same screens, and the QuickBooks header item opens Connection at `/quickbooks`.

- Connection is `/quickbooks`. `/quickbooks/connection` redirects there.
- Actions are `/quickbooks/actions`.
- Activity is `/quickbooks/activity`.

Save this organization's Client ID, Client secret, and webhook verifier on Connection. Connect uses those saved keys.

The settings form does not ask for a batch size and does not send `batch_size`.

### Enable and sync direction

Each of Customers, Invoices, Payments, and Accounts / Wallets has its own Enable checkbox, default on, and a Sync direction. Primary and Sync direction are required while that box is on. Off means that type is not synced.

| Checkbox | Stored direction | Meaning |
| --- | --- | --- |
| On, Both | `both` | Fleetbase and QuickBooks update each other |
| On, To QuickBooks | `outbound` | Fleetbase sends to QuickBooks and does not copy QuickBooks field changes back |
| On, From QuickBooks | `inbound` | QuickBooks updates Fleetbase only |
| Off | `off` | That type is not synced |

Unchecking Enable stores `off`. Checking it again keeps the last Both / To QuickBooks / From QuickBooks choice.

**Primary**, under Data Resolution, decides which system wins when the records differ and which system supplies identifiers. Primary and direction are separate. Outbound still sends the Fleetbase record when Primary is QuickBooks.

**Enable schedule** is the schedule control. Its label is not the same as each entity's Enable checkbox. Sync now and Reconcile still run when Enable schedule is off. When QuickBooks is not connected, Sync now and Reconcile return HTTP 422, save a skipped activity row, and the console shows that error.

### Environments

New setups use production. `QUICKBOOKS_ENVIRONMENT` defaults to `production`, and a blank environment on the form is saved as production. A stored `sandbox` value stays sandbox when settings are saved without changing it.

- Sandbox uses the Development keys, a sandbox QuickBooks organization, and `https://sandbox-quickbooks.api.intuit.com`.
- Production uses the Production keys, the live QuickBooks organization, and `https://quickbooks.api.intuit.com`.

### Retry

**Retry attempts** (`retry_limit`, default 5) is how many failures leave the row pending. The next failure after that marks it failed.

**Retry Delay** (`default_backoff_seconds`, default 30, at least 5) is the wait before the first retry. Each later failure waits twice as long. The wait is capped at 900 seconds.

HTTP 429 does not use Retry Delay. It uses the `Retry-After` header when that header is present, otherwise 60 seconds. A repeated 429 doubles the previous wait, still capped at 900 seconds.

## Connect and disconnect

Keys come from [developer.intuit.com](https://developer.intuit.com) → your app → Keys & credentials. Client ID and Client secret are saved on Connection for this organization and are required before connect. The secret is stored encrypted and is not shown again after save. Connect without those keys returns HTTP 422 and does not open Intuit.

The Redirect URI and the webhook URL use the scheme of the configured application URL. http stays http when the application URL is http. https is kept when the application URL is https. The host is the configured non-loopback host and the port is the configured API port. The public URL equals the internal URL. Paths stay `/quickbooks/int/v1/oauth/callback` and `/quickbooks/int/v1/webhooks`. Receiver URLs are read-only. The Redirect URI must be listed under Redirect URIs.

Connect uses PKCE (`S256`). The API stores a code verifier with the OAuth state and sends the code challenge on `https://appcenter.intuit.com/connect/oauth2` with scope `com.intuit.quickbooks.accounting`.

Intuit redirects the browser to the public route `GET /quickbooks/int/v1/oauth/callback`. That request has no Fleetbase session. It checks the single-use state, keeps the code, and sends the browser to the console at `/quickbooks?oauth_state=...`. The signed-in console finishes the connection with `POST /quickbooks/int/v1/oauth/complete`, which checks the user and organization that started it and exchanges the code at `https://oauth.platform.intuit.com/oauth2/v1/tokens/bearer`.

A failed callback returns to `/quickbooks?error=...`:

- `cancelled` — the user cancelled on Intuit.
- `state` — the OAuth state is missing, expired, or already used.
- `failed` — Intuit returned any other error.

`QUICKBOOKS_CONSOLE_HOST` is checked first, then `CONSOLE_HOST`, then `fleetbase.console.host`. One of those must be the console origin so the callback can return to the console. An `http://` or `https://` prefix is kept. If the host has no prefix, the API adds `https://`. Without a console host, the callback returns HTTP 500.

**Disconnect** deletes the local connection row only. `quickbooks_links` rows are kept. Intuit has no unsubscribe API, so disconnect does not change the Intuit app.

Home currency comes from QuickBooks Preferences. Test connection also reads Preferences. Tokens are refreshed before a sync, import, or connection test when they expire within 5 minutes. If refresh fails, the connection is marked as needing reconnection. HTTP 401 during a sync stops the batch, and the console asks to connect QuickBooks again. Copying QuickBooks customers on connect runs only when Customers is enabled in Data Resolution. If Customers is off, the import is skipped. An import does not run while a sync is running.

## Sync

`quickbooks:sync` stays registered on the Laravel scheduler, which the system cron invokes every minute, but the command does not start on a minute when no organization is due before its Sync Frequency. Flagging a record allows the next scheduler run to start it. It queues an organization when Enable schedule is on, the connection does not need reconnect, and QuickBooks is not rate limited.

An organization with nothing due does not sync. The schedule does not read a stored batch size.

**Sync now** and **Reconcile** are manual. Sync now and Reconcile still run when Enable schedule is off. Sync now uses the pending queue and does not load the whole organization. Reconcile covers invoices already in this Fleetbase organization: a local non-draft invoice, or a `quickbooks_links` row for this organization whose local type is invoice. It does not list every invoice in the QuickBooks organization.

QuickBooks update operations are posted in chunks of 20. Queries, creates, and voids are posted in chunks of at most 30.

Sync Frequency is minutes (`interval_minutes`, default 5). Full Sync Frequency (hours) (`periodic_interval_hours`, default 24) limits only the customer catalog, and that catalog is skipped when Customers is unchecked. Customer import reads QuickBooks in pages of at most 100 records and stores the next start in `quickbooks_connections.customer_import_start`, so a run that stops at the 600 second organization lock continues there.

Activity rows show created, updated, aligned, linked, skipped, unmatched, voided, and failed. Aligned is the outbound sync count. Linked is the customer-import count.

The Ledger dashboard widget is QuickBooks Sync. Its Sync now button requires a connection and a configured Client ID, Redirect URI, and Client secret.

## Webhooks

There is one public receiver: `POST /quickbooks/int/v1/webhooks`. It is outside the session and does not use CSRF. Intuit signs the raw body. Intuit's `intuit-signature` is checked only against this organization's own verifier. If that organization has no verifier configured, the webhook is rejected with HTTP 401. A verifier from another organization, a stored system token, or `QUICKBOOKS_WEBHOOK_VERIFIER` is not accepted. Unsigned posts are HTTP 401 and do not dispatch events. A valid signature returns HTTP 200 after events are dispatched. The request does not call QuickBooks.

After the signature check, each notification is applied to Fleetbase organizations connected to that `realmId`. Direction `outbound` or `off` does not sync that type. The event is still dispatched.

**Webhook Receiver URL** and **Public Receiver URL** are the same read-only value. The public URL equals the internal URL. It is computed, not stored, and saving settings does not change it. The webhook URL uses the scheme of the configured application URL. http stays http when the application URL is http. https is kept when the application URL is https. The host is the configured non-loopback host and the port is the configured API port. The console host is not used. The path stays `/quickbooks/int/v1/webhooks`.

Intuit subscription is configured in the Intuit developer portal only. Open the app, choose Webhooks, then Production or Development, and paste this URL into Endpoint URL. Subscribe to Customer, Invoice, Payment, and Account when that type's direction is From QuickBooks or Both. Fleetbase does not call an Intuit API to register the URL, choose entities, or unsubscribe a realm. `WebhookSubscriptions::apply()` and `IntuitWebhookClient` do not call Intuit.

The verifier token is stored encrypted on the organization and is not shown again after save. Leave the field blank to keep the saved token.

Other packages can listen for `Fleetbase\Quickbooks\Events\QuickBooksEntityChanged`. Each event is one entity:

- `companyUuid` — the value is the Fleetbase organization uuid
- `realmId` — QuickBooks realm id
- `entityType` — `customer`, `invoice`, `payment`, or `wallet`
- `quickbooksId` — QuickBooks id
- `operation` — `create`, `update`, or `delete`
- `localUuid` — Fleetbase uuid when a `quickbooks_links` row exists, otherwise null

`Fleetbase\Quickbooks\Listeners\EnqueueWebhookSync` queues one job per organization for the entities it will sync.

## Requirements and install

This package needs PHP `^8.2`, `fleetbase/core-api` `^1.6`, `fleetbase/fleetops-api`, and `fleetbase/ledger-api`. The Ember engine needs Node `>= 18`.

In this Fleetbase tree the plugin is `packages/quickbooks`.

- `api/composer.json` has a path repository at `../packages/quickbooks` and requires `unchartedwaters/quickbooks-api`.
- `console/package.json` links `@unchartedwaters/quickbooks-engine` to `../packages/quickbooks`.
- The console mounts that engine at `/quickbooks`.
- `console/config/environment.js` and `console/fleetbase.config.json` include `@unchartedwaters/quickbooks-engine` in `EXTENSIONS`.
- `QuickbooksServiceProvider` loads `server/src/routes.php`, `server/migrations`, and registers `quickbooks:sync` on the Laravel scheduler. The system cron invokes that scheduler every minute, but the command does not start on a minute when no organization is due before its Sync Frequency.
- `docker/api/Dockerfile.quickbooks` copies this package and runs `composer require unchartedwaters/quickbooks-api:0.0.2`.

After the files are in place, install PHP dependencies from `api/` and the console dependencies from `console/`, then run the Fleetbase migrations so the QuickBooks tables and the engine-name migration are applied. Keys are saved on Connection for that organization.

## Tests

From `packages/quickbooks`:

```bash
composer test:unit
php scripts/pest-runner.php
```

`composer test:unit` runs `php scripts/pest-runner.php`. That script needs `composer install` in this package first. `composer test:types` runs PHPStan. `composer test` also runs the PHP linter.

Ember tests need Chrome. `testem.js` launches Chrome in development and in CI.

```bash
pnpm test:ember
```

`npm run test:ember` is the same command.
