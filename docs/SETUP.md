# QuickBooks

Fleetbase extension that syncs customers, invoices, payments, and wallets (QuickBooks accounts) with QuickBooks Online.

The package lives in [unchartedWaters/fleetbase-quickbooks](https://github.com/unchartedWaters/fleetbase-quickbooks), checked out on its own, not inside the Fleetbase repository.

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

Settings are install-wide. Client ID, Client secret, Redirect URI, webhook verifier, and sync options are saved on the system rows `system.quickbooks.auth` and `system.quickbooks.sync`. Organization settings → Quickbooks Setup loads and saves them with `scope=admin`.

Organization settings lists **Quickbooks Setup** and **Quickbooks Activity** with Organization, Two Factor, and Notifications. Quickbooks Setup is route `console.settings.virtual`, slug `quickbooks-setup`, view `index` (`/settings/quickbooks-setup?view=index`). Quickbooks Activity is slug `quickbooks-activity` (`/settings/quickbooks-activity?view=index`). The QuickBooks header item opens Quickbooks Setup. There is no Admin QuickBooks panel and no Ledger settings entry.

Save the install's Client ID, Client secret, and system webhook verifier on Organization settings → Quickbooks Setup. Connect uses those saved keys. Client ID, Client secret, Redirect URI, and the webhook verifier come only from that system row. A blank system field stays blank. Environment may fall back to `QUICKBOOKS_ENVIRONMENT`, which defaults to `production`.

The settings form does not ask for a batch size and does not send `batch_size`.

### Enable and sync direction

Each of Customers, Invoices, Payments, and Accounts / Wallets is a switch, then that name. The switch defaults to on. Primary and Sync direction are required while the switch is on. Off means that type is not synced.

| Switch | Stored direction | Meaning |
| --- | --- | --- |
| On, Both | `both` | Fleetbase and QuickBooks update each other |
| On, To QuickBooks | `outbound` | Fleetbase sends to QuickBooks and does not copy QuickBooks field changes back |
| On, From QuickBooks | `inbound` | QuickBooks updates Fleetbase only |
| Off | not enabled | That type is not synced |

Turning the switch off stores that type as not enabled. Turning it on again keeps the last Both / To QuickBooks / From QuickBooks choice.

**Primary**, under Data Resolution, decides which system wins when the records differ and which system supplies identifiers. Primary and direction are separate. Outbound still sends the Fleetbase record when Primary is QuickBooks.

Sync Frequency is the schedule. It is separate from each entity switch. When QuickBooks is not connected, Sync now returns HTTP 422, saves a skipped activity row, and the console shows that error.

### Environments

New setups use production. `QUICKBOOKS_ENVIRONMENT` defaults to `production`, and a blank environment on the form is saved as production. A stored `sandbox` value stays sandbox when settings are saved without changing it.

- Sandbox uses the Development keys, a sandbox QuickBooks organization, and `https://sandbox-quickbooks.api.intuit.com`.
- Production uses the Production keys, the live QuickBooks organization, and `https://quickbooks.api.intuit.com`.

### Retry

**Retry attempts** (`retry_limit`, default 5) is how many failures leave the row pending. The next failure after that marks it failed.

**Retry Delay** (`default_backoff_seconds`, default 30, at least 5) is the wait before the first retry. Each later failure waits twice as long. The wait is capped at 900 seconds.

HTTP 429 does not use Retry Delay. It uses the `Retry-After` header when that header is present, otherwise 60 seconds. A repeated 429 doubles the previous wait, still capped at 900 seconds.

## Connect and disconnect

Keys come from [developer.intuit.com](https://developer.intuit.com) → your app → Keys & credentials. Client ID and Client secret are saved in Organization settings → Quickbooks Setup for the install and are required before connect. The secret is stored encrypted and is not shown again after save. Connect without those keys returns HTTP 422 and does not open Intuit.

The internal OAuth callback path is `/quickbooks/int/v1/oauth/callback`. The internal webhook path is `/quickbooks/int/v1/webhooks`. Quickbooks Setup shows the Fleetbase webhook receiver and the Fleetbase OAuth redirect as plain text, not inputs. Public OAuth Redirect URL and Public Webhook Receiver URL stay editable and are saved on the install-wide settings row. Paste the public OAuth URL into Intuit Redirect URIs and the public webhook URL into the Intuit Endpoint URL. A blank public URL is shown as the matching internal URL.

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

**Sync now** is on Quickbooks Setup. It uses the pending queue and does not load the whole organization. Reconcile is not a control on this screen. It covers invoices already in this Fleetbase organization: a local non-draft invoice, or a `quickbooks_links` row for this organization whose local type is invoice. It does not list every invoice in the QuickBooks organization.

QuickBooks update operations are posted in chunks of 20. Queries, creates, and voids are posted in chunks of at most 30.

Sync Frequency is minutes (`interval_minutes`, default 5). Full Sync Frequency (hours) (`periodic_interval_hours`, default 24) limits only the customer catalog, and that catalog is skipped when Customers is unchecked. Customer import reads QuickBooks in pages of at most 100 records and stores the next start in `quickbooks_connections.customer_import_start`, so a run that stops at the 600 second organization lock continues there.

Activity rows show created, updated, aligned, linked, skipped, unmatched, voided, and failed. Aligned is the outbound sync count. Linked is the customer-import count.

The Ledger dashboard widget is QuickBooks Sync. Its Sync now button requires `quickbooks reconcile sync` or an installation administrator, plus a connection and a configured Client ID, Redirect URI, and Client secret.

## Webhooks

There is one public receiver: `POST /quickbooks/int/v1/webhooks`. It is outside the session and does not use CSRF. Intuit signs the raw body. Intuit's `intuit-signature` is checked against the install-wide system webhook verifier. If that verifier is not saved, the webhook is rejected with HTTP 401. An organization verifier and `QUICKBOOKS_WEBHOOK_VERIFIER` are not accepted. Unsigned posts are HTTP 401 and do not dispatch events. A valid signature returns HTTP 200 after events are dispatched. The request does not call QuickBooks.

After the signature check, each notification is applied to Fleetbase organizations connected to that `realmId`. Direction `outbound` or `off` does not sync that type. The event is still dispatched.

Internal Webhook Receiver URL is computed from the configured origin and is not stored. Public Webhook Receiver URL is saved on the install-wide settings row when you set it. When that field is blank, the value shown is the internal URL. Paste the public URL into the Intuit Endpoint URL. The internal path stays `/quickbooks/int/v1/webhooks`. Saving settings does not register the URL with Intuit.

Intuit subscription is configured in the Intuit developer portal only. Open the app, choose Webhooks, then Production or Development, and paste this URL into Endpoint URL. Subscribe to Customer, Invoice, Payment, and Account when that type's direction is From QuickBooks or Both. Fleetbase does not call an Intuit API to register the URL, choose entities, or unsubscribe a realm. `WebhookSubscriptions::apply()` does not call Intuit.

The system webhook verifier is stored encrypted on the install-wide settings row and is not shown again after save. Leave the field blank to keep the saved token.

Other packages can listen for `Fleetbase\Quickbooks\Events\QuickBooksEntityChanged`. Each event is one entity:

- `companyUuid` — the value is the Fleetbase organization uuid
- `realmId` — QuickBooks realm id
- `entityType` — `customer`, `invoice`, `payment`, or `wallet`
- `quickbooksId` — QuickBooks id
- `operation` — `create`, `update`, or `delete`
- `localUuid` — Fleetbase uuid when a `quickbooks_links` row exists, otherwise null

`Fleetbase\Quickbooks\Listeners\EnqueueWebhookSync` queues one job per organization for the entities it will sync.

## Requirements and install

This package needs PHP `^8.2`, `fleetbase/core-api` `^1.6`, `fleetbase/fleetops-api` `0.6.71`, and `fleetbase/ledger-api` `0.0.12`. The Ember engine needs Node `>= 18`.

From the Fleetbase directory, install the extension from the Fleetbase registry:

```bash
flb install @unchartedwaters/quickbooks-engine
```

Pass `--path` with the Fleetbase directory when that directory is not the current one. `flb install` looks the package up on `https://api.fleetbase.io/~registry/v1/lookup` and installs `@unchartedwaters/quickbooks-engine` and `unchartedwaters/quickbooks-api`.

`.github/workflows/deploy.yml` publishes this package to the Fleetbase registry with `flb publish` when a `v*` tag is pushed. The tag has to contain that workflow, and the GitHub secrets named in it have to exist.

Open Organization settings → Quickbooks Setup, save the Intuit Client ID and Client secret, and connect.

### Developer checkout

On this machine the repository is checked out at `/opt/fleetbase-quickbooks` on `develop`, next to Fleetbase, not inside the Fleetbase tree. `application`, `queue`, and `scheduler` use the published `fleetbase/fleetbase-api:latest` image. That image does not contain this package. Do not build a custom API image for it.

1. Check out this repository to `/opt/fleetbase-quickbooks` on `develop`.
2. Point the Fleetbase app at that directory. Composer path repository `../../fleetbase-quickbooks`, console dependency `link:../../fleetbase-quickbooks`, and a read-only mount of `/opt/fleetbase-quickbooks` at `/fleetbase/packages/quickbooks`. A symlink at `packages/quickbooks` can point at the same checkout. These Fleetbase edits stay local and are not committed to `fleetbase/fleetbase`.
3. Put the existing Fleetbase `APP_KEY` in `api/.env`. Use the key that already decrypts this install.
4. Start the stack with Docker Compose. `docker-compose.yml` mounts `./api/.env` into `application`, `queue`, and `scheduler`, so all three read the same `APP_KEY`. It mounts `/opt/fleetbase-quickbooks` read-only at `/fleetbase/packages/quickbooks`. The entrypoint for those three services is `/fleetbase/packages/quickbooks/docker/ensure-quickbooks-extension.sh`. Console and API image builds take the package from a BuildKit context named `quickbooks`, not from a copy inside the Fleetbase tree.
5. On start, that script exits with an error if `/fleetbase/packages/quickbooks` is missing. It Composer-requires `unchartedwaters/quickbooks-api:0.0.2` when the provider is not installed, or when the mounted `composer.json` version or `require` entries differ from the installed package. Only `application` runs `php artisan migrate --force`. `queue` and `scheduler` do not migrate on startup. A later application start skips the require when the installed package still matches, and migrate applies only pending migrations.
6. Install console dependencies from `console/`. `console/package.json` links `@unchartedwaters/quickbooks-engine` to `../../fleetbase-quickbooks`. The console mounts that engine at `/quickbooks`. `console/fleetbase.config.json` lists `@unchartedwaters/quickbooks-engine` in `EXTENSIONS`.

`api/composer.json` requires `unchartedwaters/quickbooks-api` and has a path repository at `../../fleetbase-quickbooks`. The running containers get the package from the ensure script, not from a rebuilt image.

`QuickbooksServiceProvider` loads `server/src/routes.php`, `server/migrations`, and registers `quickbooks:sync` on the Laravel scheduler. The system cron invokes that scheduler every minute, but the command does not start on a minute when no organization is due before its Sync Frequency.

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
