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

Settings are install-wide, and only an installation administrator can save them. An organization role can view the settings (`quickbooks view settings`) and `GET settings` returns `can_edit`, but `POST settings` returns HTTP 403, and the console shows the form read-only with a note. Client ID, Client secret, Redirect URI, webhook verifier, and sync options are saved on the system rows `system.quickbooks.auth` and `system.quickbooks.sync`. Organization settings → Quickbooks Setup loads and saves them with `scope=admin`.

Organization settings lists **Quickbooks Setup** and **Quickbooks Activity** with Organization, Two Factor, and Notifications. Quickbooks Setup is route `console.settings.virtual`, slug `quickbooks-setup`, view `index` (`/settings/quickbooks-setup?view=index`). Quickbooks Activity is slug `quickbooks-activity` (`/settings/quickbooks-activity?view=index`). The QuickBooks header item opens Quickbooks Setup. There is no Admin QuickBooks panel and no Ledger settings entry.

Save the install's Client ID, Client secret, and system webhook verifier on Organization settings → Quickbooks Setup. Connect uses those saved keys. Client ID, Client secret, Redirect URI, and the webhook verifier come only from that system row. A blank system field stays blank. Environment may fall back to `QUICKBOOKS_ENVIRONMENT`, which defaults to `production`.

The settings form does not ask for a batch size and does not send `batch_size`.

### Enable and sync direction

Each of Customers, Invoices, Payments, and Accounts / Wallets is a switch, then that name. The switch defaults to on, except Accounts / Wallets, which defaults to off on a fresh install because a wallet per driver or customer can flood the QuickBooks chart of accounts. Turning wallets on queues the existing wallets. An install that already saved a value keeps it; an install that never saved settings and relied on the old default must switch wallets on again. Primary and Sync direction are required while the switch is on. Off means that type is not synced.

| Switch | Stored direction | Meaning |
| --- | --- | --- |
| On, Both | `both` | Fleetbase and QuickBooks update each other |
| On, To QuickBooks | `outbound` | Fleetbase sends to QuickBooks and does not copy QuickBooks field changes back |
| On, From QuickBooks | `inbound` | QuickBooks updates Fleetbase only |
| Off | not enabled | That type is not synced |

Turning the switch off stores that type as not enabled. Turning it on again keeps the last Both / To QuickBooks / From QuickBooks choice.

**Primary**, under Data Resolution, decides which system wins when the records differ and which system supplies identifiers. Primary and direction are separate. Outbound still sends the Fleetbase record when Primary is QuickBooks.

Sync Frequency is the schedule. It is separate from each entity switch. There is no Enable schedule control, and a stored `enabled` flag does not stop the scheduler. Sync now with no connection returns HTTP 422 and does not save an activity row. The console shows that error. A stored realm that needs reconnect returns HTTP 422 and saves a skipped activity row.

### Environments

New setups use production. The settings default is `production`: `server/config/quickbooks.php` reads `QUICKBOOKS_ENVIRONMENT` with a default of `production`, and a blank environment on the form is saved as production. A stored `sandbox` value stays sandbox when settings are saved without changing it.

The `quickbooks_connections.environment` column is a different thing. Its migration default is `sandbox`, but connect always writes the environment from settings onto the connection row, so that default is only a fallback for a row created some other way. API calls use the environment stored on the connection, not the current setting, and a connection with no environment is treated as sandbox. After you switch the environment in settings, connect again.

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

The callback is limited per client IP to `quickbooks.oauth.callback_per_minute` requests per minute (default 30; 0 turns the limit off). Behind a reverse proxy, configure the host application's trusted proxies so the client IP is the user's, not the proxy's; otherwise every user shares one limit. The `realmId` must be 6 to 20 digits. If the first Intuit call for a new connection returns 401, 403, or 404, the connection is not stored. A failed callback returns to `/quickbooks?error=...`:

- `cancelled` — the user cancelled on Intuit.
- `state` — the OAuth state is missing, expired, or already used, or the `realmId` is not valid.
- `failed` — Intuit returned any other error.

`QUICKBOOKS_CONSOLE_HOST` is checked first, then `CONSOLE_HOST`, then `fleetbase.console.host`. One of those must be the console origin so the callback can return to the console. An `http://` or `https://` prefix is kept. If the host has no prefix, the API adds `https://`. Without a console host, the callback returns HTTP 500.

**Disconnect** revokes the refresh token at Intuit (`developer.api.intuit.com/v2/oauth2/tokens/revoke`) on a best-effort basis, then deletes the local connection row and this organization's still-pending sync rows. A failed revoke does not block the disconnect. `quickbooks_links` rows are kept so a later reconnect restores the mappings. Disconnect does not change the Intuit app or its webhook subscription.

Home currency comes from QuickBooks Preferences. Test connection also reads Preferences. Tokens are refreshed before a sync, import, webhook job, or connection test when they expire within 5 minutes. HTTP 401 during a sync first refreshes the token and retries that call once. The console asks to connect QuickBooks again only when Intuit refuses the refresh token (`invalid_grant`) or the new token is also refused. A refresh that fails for another reason (for example a 503) does not ask to reconnect: the row that hit it is retried later, the rest of that run is left pending, and the refresh is not tried again until the next scheduled run (a Sync now or drain run does not queue another run straight away). The same refresh-and-retry applies when QuickBooks answers a batch with an authentication fault on every item instead of HTTP 401; a batch where only some items were refused is not repeated, so the accepted items are not written twice. A large batch is sent in chunks; when a later chunk is refused, the earlier chunks' results are kept and the refused and remaining items are retried on the next run. Copying QuickBooks customers on connect runs only when Customers is enabled in Data Resolution. If Customers is off, the import is skipped. An import does not run while a sync is running.

## Sync

`quickbooks:sync` stays registered on the Laravel scheduler, which the system cron invokes every minute. There is no Enable schedule control. The form does not send `sync.enabled`, and the command does not read a stored `enabled` flag, so `enabled: false` does not stop a due sync. The command does not start when no connection can be synced, or while a schedule hold is still in the future. It queues an organization when the connection does not need reconnect, QuickBooks is not rate limited, and either Sync Frequency has elapsed with at least one due pending row, or at least 20 pending rows are already due. The customer catalog is the other reason a minute can start work. It is skipped when Customers is off.

One flagged row does not start the next minute. Flagging clears a stored schedule hold, so the next `schedule:run` may start the command, but the command still waits until Sync Frequency (`interval_minutes`, default 5) has elapsed after `last_batch_at`, unless 20 or more rows are already due. A first sync, with no `last_batch_at`, can start from one due row. After a run that queues work, the command holds the next start for 60 seconds.

An organization with nothing due does not sync. The schedule does not read a stored batch size. Each run still stops after one page. That page is the stored `batch_size` when an older settings row has one, otherwise 100. The settings form does not send `batch_size`.

**Sync now** is on Quickbooks Setup. It uses the pending queue and does not load the whole organization. It processes one pending page and does not drain the rest of the queue. Leftover rows wait for the schedule above. Drain is chained from a finished reconcile, and from a finished drain that still has due rows. Sync now does not chain it. Reconcile is not a control on this screen. It covers invoices already in this Fleetbase organization: a local non-draft invoice, or a `quickbooks_links` row for this organization whose local type is invoice. It does not list every invoice in the QuickBooks organization.

QuickBooks update operations are posted in chunks of 20. Queries, creates, and voids are posted in chunks of at most 30.

Sync Frequency is minutes (`interval_minutes`, default 5). Full Sync Frequency (hours) (`periodic_interval_hours`, default 24) limits only the customer catalog, and that catalog is skipped when Customers is unchecked. Customer import reads QuickBooks in pages of at most 100 records and stores the next start in `quickbooks_connections.customer_import_start`, so a run that stops at the 600 second organization lock continues there.

Sync now with no connection returns HTTP 422 and does not save an activity row. The console shows that error. A stored realm that needs reconnect returns HTTP 422 and saves a skipped activity row.

Activity rows show created, updated, aligned, linked, skipped, unmatched, voided, and failed. Aligned counts inbound matches as well as outbound ones, including an inbound customer or invoice that already matched and was not written. A From QuickBooks run that changes nothing can show aligned. Linked is the customer-import count.

The Ledger dashboard widget is QuickBooks Sync. Its Sync now button requires `quickbooks reconcile sync` or an installation administrator, plus a connection and a saved Client ID and Client secret. It does not require a Redirect URI. OAuth connect still needs the public https redirect.

## Webhooks

There is one public receiver: `POST /quickbooks/int/v1/webhooks`. It is outside the session and does not use CSRF. Intuit signs the raw body. Intuit's `intuit-signature` is checked against the install-wide system webhook verifier. If that verifier is not saved, the webhook is rejected with HTTP 401. An organization verifier and `QUICKBOOKS_WEBHOOK_VERIFIER` are not accepted. Unsigned posts are HTTP 401 and do not dispatch events. A valid signature returns HTTP 200 after events are dispatched. A valid signature for a realm with no connection (for example after a disconnect) also returns HTTP 200 and does nothing. If processing fails, the request returns a 5xx and Intuit's retry of the same body is processed. While a delivery is being processed its body is remembered for 120 seconds only, so a worker that dies mid-request does not block Intuit's retry; once processed, an exact repeat is HTTP 401 for `quickbooks.webhook.max_age_seconds` (default 600). A delivery whose entity `lastUpdated` is older than that age is rejected with HTTP 401, whether or not its realm is connected. The request itself does not call QuickBooks: payment lookups run in the queued job `ResolveWebhookPayments`, so queue workers must be running.

After the signature check, each notification is applied to Fleetbase organizations connected to that `realmId`. Direction `outbound` or `off` does not sync that type. The event is still dispatched. This also applies to deletes and voids. A delete or void in QuickBooks changes Fleetbase (void the invoice, remove the customer, close and remove the wallet, unmark the paid invoice for a deleted payment) only when the type is enabled, the direction takes inbound, and QuickBooks is primary (or the direction is `inbound`). Otherwise Fleetbase keeps its record and the deletion is ignored. A wallet whose balance is not zero is never closed from QuickBooks, whether by a delete or by an account that is made inactive: it stays open and linked, its pending sync is finished so the deleted account is not reactivated at once, and a warning is logged with its id. A later change to that wallet in Fleetbase pushes it again and makes the account active. Move the balance out first if the wallet should close.

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

## Known limitations

- **Currency.** An invoice whose currency differs from the QuickBooks home currency syncs only when QuickBooks multi-currency is enabled (Settings, Advanced, Currency). Otherwise the row fails with "Invoice currency EUR does not match QuickBooks home currency USD" and nothing is sent; wallets behave the same way. With multi-currency on, QuickBooks applies its own exchange rate because no `ExchangeRate` is sent. Invoices that were posted in the home currency by an earlier version stay aligned until a push is attempted.
- **Invoice numbers.** With `invoice_reference=quickbooks`, a QuickBooks DocNumber that another Fleetbase invoice (any organization) already uses is not copied. The invoice keeps its number and the Activity row shows a note. The note repeats on each sync until the duplicate is resolved.
- **Quantities.** Fleetbase invoice line quantities are whole numbers. A fractional QuickBooks quantity imports as 1 unit at the line amount.
- **Tax.** Invoice tax is sent as an ordinary sales line whose description is `Tax` prefixed with a word joiner (`Support/InvoiceMapper.php`). No `TxnTaxDetail` or tax code is sent. A QuickBooks company that uses automated sales tax can show different tax and totals than Fleetbase. On the way back, only that synthetic line is read as tax.
- **Partial payments.** An invoice has at most one QuickBooks Payment from this package. Partial and later payments are not created as separate Payments. The one Payment is created with the amount recorded as paid on the Fleetbase invoice (its total when the invoice is paid and no amount is recorded) and is updated when that amount changes (`SyncEngine::syncPayment`). If an invoice has more than one QuickBooks payment, or the payment also applies to other invoices, Fleetbase leaves them unchanged and the row is skipped.
- **Wallets.** Each Fleetbase wallet becomes one QuickBooks chart-of-accounts entry (`Other Current Asset`).
- **Shared QuickBooks company.** Several Fleetbase organizations can connect to the same QuickBooks company (realm). `quickbooks_connections` is unique per organization, not per realm, and a webhook for a realm is applied to every organization connected to it (`WebhookController`). Intuit app credentials, the redirect, and the webhook verifier are install-wide, so every organization on the install uses the same Intuit app.
- **Links.** `quickbooks_links` holds links for customers, invoices, and wallets, and also for payments. A payment link is keyed by the Fleetbase invoice uuid, or by the QuickBooks payment id when the payment was found in QuickBooks.
- **Customer matching.** A Fleetbase customer is matched to an existing QuickBooks customer by email first, then by display name. When Primary is Fleetbase, a name match is rejected if both sides have an email and the emails differ, so that customer is not matched. Names are the only key when there is no email, so two different customers with the same name are treated as one.

## Overlapping syncs and history

A running sync leases the pending rows it loaded (`claimed_until`, `claimed_by` on `quickbooks_pending_syncs`) so a second run, such as the one a webhook starts while the first is waiting on QuickBooks, cannot send the same row again. A lease left by a killed worker expires after `quickbooks.sync.claim_seconds` (default 900; keep it above the 570 second job timeout). Run the migrations before enabling more than one queue worker; until the columns exist, runs work but are not protected against overlap. A Fleetbase change flagged while its row is leased marks the lease (`claimed_by` gets a `reflag:` prefix), so the running sync leaves that row pending (still recording the attempt and backoff) and the next run sends the new change. A mark left by a run that died stops counting once its lease expires.

A sync saves its links first, in their own transaction, then the pending rows, attempts and Fleetbase records together. If the second part fails, the links to records already created in QuickBooks are kept, the rows stay pending, and the retry updates those records instead of creating them again.

`quickbooks:prune` runs daily and deletes sync attempts older than `quickbooks.retention.attempt_days` (90), finished or skipped batches older than `quickbooks.retention.batch_days` (180) that have no attempts left, and done pending rows older than `quickbooks.retention.pending_days` (30). A value of 0 keeps that history forever. Pending and failed rows and links are never pruned.

A company with no QuickBooks connection is looked up at most once per 30 seconds per PHP process when Fleetbase records change, and a company that connects can take up to 30 seconds to start being flagged; connecting already queues all existing records. Failures while flagging a Fleetbase change are logged as warnings ("QuickBooks could not flag a Fleetbase … change") with ids only and never block the Fleetbase save.

## Requirements and install

This package needs PHP `^8.2`, `fleetbase/core-api` `^1.6`, `fleetbase/fleetops-api` `0.6.71`, and `fleetbase/ledger-api` `0.0.12`. The Ember engine needs Node `>= 22`.

From the Fleetbase directory, install the extension from the Fleetbase registry:

```bash
flb install @unchartedwaters/quickbooks-engine
```

Pass `--path` with the Fleetbase directory when that directory is not the current one. `flb install` looks the package up on `https://api.fleetbase.io/~registry/v1/lookup` and installs `@unchartedwaters/quickbooks-engine` and `unchartedwaters/quickbooks-api`.

`.github/workflows/deploy.yml` publishes this package to the Fleetbase registry with `flb publish` when a `v*` tag is pushed. The tag has to contain that workflow, and the GitHub secrets named in it have to exist.

Open Organization settings → Quickbooks Setup, save the Intuit Client ID and Client secret, and connect.

### Developer checkout

On this machine the repository is checked out at `/opt/fleetbase-quickbooks` on `main`, next to Fleetbase, not inside the Fleetbase tree. `application`, `queue`, and `scheduler` use the published `fleetbase/fleetbase-api:latest` image. That image does not contain this package. Do not build a custom API image for it. The running console bakes this engine into its image. A change in this checkout shows up in the API through the read-only mount. The console UI stays at that image build until the console image is rebuilt.

1. Check out this repository to `/opt/fleetbase-quickbooks` on `main`.
2. Point the Fleetbase app at that directory. Composer path repository `../../fleetbase-quickbooks`, console dependency `link:../../fleetbase-quickbooks`, and a read-only mount of `/opt/fleetbase-quickbooks` at `/fleetbase/packages/quickbooks`. A symlink at `packages/quickbooks` can point at the same checkout. These Fleetbase edits stay local and are not committed to `fleetbase/fleetbase`.
3. Put the existing Fleetbase `APP_KEY` in `api/.env`. Use the key that already decrypts this install.
4. Start the stack with Docker Compose. `docker-compose.yml` mounts `./api/.env` into `application`, `queue`, and `scheduler`, so all three read the same `APP_KEY`. It mounts `/opt/fleetbase-quickbooks` read-only at `/fleetbase/packages/quickbooks`. The entrypoint for those three services is `/fleetbase/packages/quickbooks/docker/ensure-quickbooks-extension.sh`. Console and API image builds take the package from a BuildKit context named `quickbooks`, not from a copy inside the Fleetbase tree.
5. On start, that script exits with an error if `/fleetbase/packages/quickbooks` is missing. It Composer-requires `unchartedwaters/quickbooks-api:0.0.2` when the provider is not installed, or when the mounted `composer.json` version or `require` entries differ from the installed package. Only `application` runs `php artisan migrate --force`. `queue` and `scheduler` do not migrate on startup. A later application start skips the require when the installed package still matches, and migrate applies only pending migrations.
6. Install console dependencies from `console/`. `console/package.json` links `@unchartedwaters/quickbooks-engine` to `../../fleetbase-quickbooks`. `console/fleetbase.config.json` lists `@unchartedwaters/quickbooks-engine` in `EXTENSIONS`. The running console bakes the engine into the image at `/usr/share/nginx/html/engines-dist/@unchartedwaters/quickbooks-engine`, from the BuildKit context named `quickbooks`. That container's bind is `console/fleetbase.config.json`.

### Developing locally

Starting OAuth needs a public https redirect URL. The redirect that Intuit receives is the `public_oauth_redirect_url` setting (Public OAuth Redirect URL on Quickbooks Setup) when it is set, otherwise the computed callback on the API host. Either one must pass `Support/PublicHttps`: it has to be `https://`, and localhost, loopback, private, link-local, and other internal addresses are rejected, as is a hostname that does not resolve to a public address. A dev server on a private IP such as `10.30.0.34` therefore cannot connect as it is. Put a public https tunnel or reverse proxy in front of the API, save its callback URL (`https://<public host>/quickbooks/int/v1/oauth/callback`) as Public OAuth Redirect URL, and list the same URL under Redirect URIs in the Intuit app. Without a usable redirect, connect returns HTTP 422 and does not open Intuit.

Set `CONSOLE_HOST` (or `QUICKBOOKS_CONSOLE_HOST`) on the API to the console origin. The OAuth callback redirects the browser there, and returns HTTP 500 without it. Sync now and the schedule do not need the public redirect once a connection exists.

`api/composer.json` requires `unchartedwaters/quickbooks-api` and has a path repository at `../../fleetbase-quickbooks`. The running `application`, `queue`, and `scheduler` containers get the package from the ensure script and the read-only mount. The console UI is the copy baked into the console image.

`QuickbooksServiceProvider` loads `server/src/routes.php`, `server/migrations`, and registers `quickbooks:sync` on the Laravel scheduler. The system cron invokes that scheduler every minute. The command does not read a stored `enabled` flag. It does not start when no connection can be synced, or while a schedule hold is still in the future. The Sync section is when an organization is queued.

## Tests

PHP 8.2 is the supported version. It matches Fleetbase and is the version CI runs (`.github/workflows/server.yml`).

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
