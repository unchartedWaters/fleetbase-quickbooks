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

The screenshots in this guide use placeholder values (an example Client ID,
company number, and URLs) and sample activity. They show the extension's own
screens without the surrounding Fleetbase console menus.

## What syncs

- **Customers** — name, email, phone, notes, and billing address.
- **Invoices** — number, dates, notes, currency, tax, total, and the sales-item lines Fleetbase understands (description, quantity, unit price, and amount). Draft invoices are sent after they leave draft. QuickBooks products, discounts, and other line types are not copied back.
- **Payments** — a payment for a paid Fleetbase invoice. Amount and date are compared when a payment already exists. The payload includes `TxnDate`. A QuickBooks payment that also applies to other invoices is left unchanged.
- **Wallets** — a QuickBooks account (`Other Current Asset`). Fleetbase sends the wallet public ID as `AcctNum` only when that ID is 7 characters or fewer. Longer public IDs, such as `wallet_` plus 10 characters, are not sent. If the link is lost, a unique account with the same name and currency is reattached instead of creating a duplicate. When QuickBooks is primary, the account name and `AcctNum` are copied onto the wallet.

Unknown QuickBooks invoices are not imported. An invoice is synced from QuickBooks only when this Fleetbase organization already has that invoice or a `quickbooks_links` row for it. The webhook event is still dispatched for those invoices.

Money is stored as integer minor units. QuickBooks major-unit amounts are converted with `Amounts::toMinorUnits` before they are saved, and a PHP float is rejected. Unit price on an invoice line is an integer minor-unit value. When it is sent to QuickBooks it is formatted as a decimal string, not a float.

## Create a QuickBooks app

The extension connects through an app that you create on the Intuit Developer
Portal. One app serves the whole install. Intuit's own guides cover each step:

1. Create a developer account and an app: [Create and start developing your
   app][intuit-create-app]. When Intuit asks for scopes, choose Accounting.
   Connect asks only for `com.intuit.quickbooks.accounting` ([Learn about
   scopes][intuit-scopes]), so Payments is not needed. Intuit creates a sandbox
   company to test with: [Create and test with a sandbox
   company][intuit-sandbox].
2. On Admin → QuickBooks → Settings, set Public OAuth Redirect URL and Public
   Webhook Receiver URL to your public https host and save. A blank field shows
   the internal URL, which Intuit cannot reach on a private install. Intuit
   allows `localhost` for sandbox apps, but this extension requires a public
   https URL (see [Developing locally](#developing-locally)).
3. Copy the Client ID and Client secret from the app's Keys & credentials:
   [Get the Client ID and Client Secret][intuit-keys]. Development keys work
   only with sandbox companies; choose Sandbox for Environment. Production keys
   need Intuit's production App Details and an approved App Assessment
   Questionnaire: [Publish your app][intuit-publish]. Listing on the QuickBooks
   App Store is not required. Environment defaults to Production; after
   switching it, each organization connects again.
4. Add the Public OAuth Redirect URL to the app's Redirect URIs, under
   Development or Production to match the keys: [Set app redirect
   URIs][intuit-redirect].
5. If any type syncs From QuickBooks or Both, open Webhooks under the same
   Development or Production tab, enter the Public Webhook Receiver URL as the
   Endpoint URL, choose the entities (see [Webhooks](#webhooks)), and copy that
   tab's Verifier Token into **Webhook verifier token**: [Configure
   webhooks][intuit-webhooks].
6. Save the keys on Admin → QuickBooks → Settings. The API also needs
   `CONSOLE_HOST` (see [Connect and disconnect](#connect-and-disconnect)), and
   webhooks need running queue workers.

[intuit-create-app]: https://developer.intuit.com/app/developer/qbo/docs/get-started/start-developing-your-app
[intuit-sandbox]: https://developer.intuit.com/app/developer/qbo/docs/develop/sandboxes/manage-your-sandboxes
[intuit-keys]: https://developer.intuit.com/app/developer/qbo/docs/get-started/get-client-id-and-client-secret
[intuit-publish]: https://developer.intuit.com/app/developer/qbo/docs/go-live/publish-app
[intuit-redirect]: https://developer.intuit.com/app/developer/qbo/docs/develop/authentication-and-authorization/set-redirect-uri
[intuit-webhooks]: https://developer.intuit.com/app/developer/qbo/docs/develop/webhooks/configure-webhooks
[intuit-scopes]: https://developer.intuit.com/app/developer/qbo/docs/learn/scopes

## Settings

Global settings are Client ID, Client secret, Redirect URI, environment, webhook
verifier, public webhook and OAuth URLs. Only an installation administrator can
view or save them, on Admin → QuickBooks → Settings, which uses `scope=admin`
and the system rows `system.quickbooks.auth` and `system.quickbooks.sync`
(others get HTTP 403).

The sync options on that screen are strictly a template. Changing them has no
effect on any organization's sync settings, and a global save queues nothing for
organizations. Credentials, the webhook verifier, environment, and URLs are
global and admin-only.

![Admin, QuickBooks, Settings: the template note, then Authentication
with Environment, Client ID, masked secret and verifier, and the webhook and
OAuth URLs][admin-settings]

Organization settings → QuickBooks Setup uses `scope=company` (the default). It
shows the connection controls and the organization's own sync options. An
organization can change only sync options. It never sees or saves credentials,
the webhook verifier, environment, or URLs. The organization's settings are
stored on `company.{uuid}.quickbooks.sync` with a `scope: organization` marker;
organization rows written by older builds (no marker) are ignored.
`GET settings` at company scope returns only `can_edit`, `sync`, `has_copy`, and
`credentials_configured`. `POST settings` at company scope accepts only `scope`,
`sync`, `reset`, and the caller's own `company_uuid`; an `auth` key or any other
key returns HTTP 422. A save applies the submitted sync fields to the current
settings and stores the whole result as the organization's copy. The sync
summary used by the widget returns `credentials_configured`, not the Client ID.

Saving organization settings, or resetting them, needs
`quickbooks update settings`, which the QuickBooks Administrator policy grants.
QuickBooks Operator can only view (`quickbooks view settings`) and the console
shows the form read-only with a note. Installation administrators always pass.
On an existing install, re-seed permissions so `update settings` exists:
`php artisan fleetbase:create-permissions` from the Fleetbase API directory
(Fleetbase core command; `--reset` rebuilds all permissions, policies, and
roles). Every sync reader (schedule, Sync now, webhooks, customer import, remote
changes) uses the organization's own copy of the sync settings. An organization
with no copy yet uses the global row over the built-in defaults.

![QuickBooks Setup for a QuickBooks Operator: the sync settings are
read-only, with a note and a disabled Save Changes][setup-read-only]

An organization gets its own complete copy of the sync settings in three cases.
It gets one when it connects (before its existing records are queued), when it
saves its settings, and when it uses **Reset to global defaults**, which is a
one-time copy of the current template. Reconnecting keeps the existing copy.
There is no override switch and no per-field inheritance. Before an organization
has a copy, its settings screen shows the current global defaults as starting
values.

![QuickBooks Setup before connecting: Connect to QuickBooks, and a note
that the settings shown are the current global defaults][setup-not-connected]

To upgrade, run `php artisan migrate`. The new migration gives every existing
organization (with a QuickBooks connection or an organization settings row) a
copy of its current effective settings, so nothing changes for them at upgrade.
It is also required for the earlier `quickbooks_links` index migration.

Organization settings lists **QuickBooks Setup** and **QuickBooks Activity**
with Organization, Two Factor, and Notifications. QuickBooks Setup is route
`console.settings.virtual`, slug `quickbooks-setup`, view `index`
(`/settings/quickbooks-setup?view=index`). QuickBooks Activity is slug
`quickbooks-activity` (`/settings/quickbooks-activity?view=index`). The
QuickBooks header item opens QuickBooks Setup. The Fleetbase Admin panel
**QuickBooks** has one item, **Settings**. There is no Ledger settings entry.

An installation administrator saves the install's Client ID, Client secret, and
system webhook verifier on Admin → QuickBooks → Settings. Connect on QuickBooks
Setup uses those saved keys. Client ID, Client secret, Redirect URI, and the
webhook verifier come only from that system row. A blank system field stays
blank. Environment may fall back to `QUICKBOOKS_ENVIRONMENT`, which defaults to
`production`.

Client ID and Client secret are required together. A blank Client secret keeps
the saved one only while the Client ID stays the same. A changed Client ID needs
its own Client secret, or the save is refused. If a webhook verifier is saved,
a changed Client ID also needs that app's Verifier Token from its Webhooks page.

A saved Client secret or webhook verifier shows as dots and its last four
characters, such as `••••••••a1b2`; the full value is never sent back. A value
shorter than 16 characters shows only dots. A key you type or paste stays
visible until you save; the eye button next to the field hides or shows it.

The settings form does not ask for a batch size and does not send `batch_size`.

### Permissions

Assign one of these roles to non-administrator users. Roles and policies come
from `server/src/Auth/Schemas/Quickbooks.php`.

| Role (policy) | Permissions |
| --- | --- |
| QuickBooks Administrator (`QuickbooksAdministrator`) | `quickbooks see extension`, `quickbooks * connection`, `quickbooks * settings`, `quickbooks * sync` |
| QuickBooks Operator (`QuickbooksOperator`) | `quickbooks see extension`, `quickbooks view`, `connect`, `disconnect` and `import-customers connection`, `quickbooks view settings`, `quickbooks view sync`, `quickbooks reconcile sync` |

`quickbooks see extension` opens QuickBooks Setup. Sync now authorizes as
`quickbooks reconcile sync`. Global settings (Admin → QuickBooks → Settings)
need an installation administrator; no role grants them. Installation
administrators bypass all of these checks.

### Enable and sync direction

Each of Customers, Invoices, Payments, and Accounts / Wallets is a switch, then
that name. The switch defaults to on, except Accounts / Wallets, which defaults
to off on a fresh install because a wallet per driver or customer can flood the
QuickBooks chart of accounts. Turning wallets on queues the existing wallets.
Queueing runs in a background job, so queue workers must be running. Turning an
entity on or connecting queues this way. Changing the global sync template
queues nothing. An install that already saved a value keeps it; an install that
never saved settings and relied on the old default must switch wallets on again.
Primary and Sync direction are required while the switch is on. Off means that
type is not synced.

![Data Resolution: Customers, Invoices, and Payments switched on with
Primary and Sync direction; Accounts / Wallets off][setup-data-resolution]

| Switch | Stored direction | Meaning |
| --- | --- | --- |
| On, Both | `both` | Fleetbase and QuickBooks update each other |
| On, To QuickBooks | `outbound` | Fleetbase sends to QuickBooks and does not copy QuickBooks field changes back |
| On, From QuickBooks | `inbound` | QuickBooks updates Fleetbase only |
| Off | not enabled | That type is not synced |

Turning the switch off stores that type as not enabled. Turning it on again keeps the last Both / To QuickBooks / From QuickBooks choice.

**Primary**, under Data Resolution, decides which system wins when the records
differ and which system supplies identifiers. Primary and direction are
separate. To QuickBooks still sends the Fleetbase record when Primary is
QuickBooks.

Sync frequency is the schedule. It is separate from each entity switch. There is
no Enable schedule control, and a stored `enabled` flag does not stop the
scheduler. Sync now with no connection returns HTTP 422 and does not save an
activity row. The console shows that error. A stored realm that needs reconnect
returns HTTP 422 and saves a skipped activity row.

### Environments

New setups use production. The settings default is `production`: `server/config/quickbooks.php` reads `QUICKBOOKS_ENVIRONMENT` with a default of `production`, and a blank environment on the form is saved as production. A stored `sandbox` value stays sandbox when settings are saved without changing it.

The `quickbooks_connections.environment` column is a different thing. Its migration default is `sandbox`, but connect always writes the environment from settings onto the connection row, so that default is only a fallback for a row created some other way. API calls use the environment stored on the connection, not the current setting, and a connection with no environment is treated as sandbox. After you switch the environment in settings, connect again.

- Sandbox uses the Development keys, a QuickBooks sandbox company, and
  `https://sandbox-quickbooks.api.intuit.com`.
- Production uses the Production keys, your live QuickBooks company, and
  `https://quickbooks.api.intuit.com`.

### Retry

**Retry attempts** (`retry_limit`, default 5) is how many failures leave the row pending. The next failure after that marks it failed.

**Retry delay** (`default_backoff_seconds`, default 30, at least 5) is the wait
before the first retry. Each later failure waits twice as long. The wait is
capped at 900 seconds.

HTTP 429 does not use Retry delay. It uses the `Retry-After` header when that
header is present, otherwise 60 seconds. A repeated 429 doubles the previous
wait, still capped at 900 seconds.

## Connect and disconnect

Keys come from [developer.intuit.com](https://developer.intuit.com) → your app → Keys & credentials. Client ID and Client secret are saved by an installation administrator in Admin → QuickBooks → Settings for the install and are required before connect. The secret is stored encrypted and is not shown again after save. Connect without those keys returns HTTP 422 and does not open Intuit.

The internal OAuth callback path is `/quickbooks/int/v1/oauth/callback`. The internal webhook path is `/quickbooks/int/v1/webhooks`. Admin → QuickBooks → Settings shows the Fleetbase webhook receiver and the Fleetbase OAuth redirect as plain text, not inputs. Public OAuth Redirect URL and Public Webhook Receiver URL stay editable and are saved on the install-wide settings row. Paste the public OAuth URL into Intuit Redirect URIs and the public webhook URL into the Intuit Endpoint URL. A blank public URL is shown as the matching internal URL.

The Connection card on QuickBooks Setup shows only the actions that apply.
Before a company is connected it offers **Connect to QuickBooks**. Once
connected it offers **Sync now** and **Disconnect**. When QuickBooks must be
connected again it offers **Connect to QuickBooks** and **Disconnect**. An
action the user lacks permission for stays visible but disabled.

![QuickBooks Setup when connected: company, environment, home currency
notes, Sync now, and Disconnect][setup-connected]

![QuickBooks Setup when QuickBooks must be connected again: a red
message, Connect to QuickBooks, and Disconnect][setup-reconnect]

Connect uses PKCE (`S256`). The API stores a code verifier with the OAuth state and sends the code challenge on `https://appcenter.intuit.com/connect/oauth2` with scope `com.intuit.quickbooks.accounting`.

Intuit redirects the browser to the public route `GET /quickbooks/int/v1/oauth/callback`. That request has no Fleetbase session. It checks the single-use state, keeps the code, and sends the browser to the console at `/quickbooks?oauth_state=...`. The signed-in console finishes the connection with `POST /quickbooks/int/v1/oauth/complete`, which checks the user and organization that started it and exchanges the code at `https://oauth.platform.intuit.com/oauth2/v1/tokens/bearer`.

The callback is limited per client IP to `quickbooks.oauth.callback_per_minute` requests per minute (default 30; 0 turns the limit off). Behind a reverse proxy, configure the host application's trusted proxies so the client IP is the user's, not the proxy's; otherwise every user shares one limit. The `realmId` must be 6 to 20 digits. If the first Intuit call for a new connection returns 401, 403, or 404, the connection is not stored. A failed callback returns to `/quickbooks?error=...`:

- `cancelled` — the user cancelled on Intuit.
- `state` — the OAuth state is missing, expired, or already used, or the `realmId` is not valid.
- `failed` — Intuit returned any other error.

`QUICKBOOKS_CONSOLE_HOST` is checked first, then `CONSOLE_HOST`, then
`fleetbase.console.host`. That last fallback counts only when it is set to
something other than its default `fleetbase.io`, so set `CONSOLE_HOST` (or
`QUICKBOOKS_CONSOLE_HOST`) explicitly. One of those must be the console origin
so the callback can return to the console. An `http://` or `https://` prefix is
kept. If the host has no prefix, the API adds `https://`. Without a console
host, the callback returns HTTP 500.

**Disconnect** revokes the refresh token at Intuit (`developer.api.intuit.com/v2/oauth2/tokens/revoke`) on a best-effort basis, then deletes the local connection row and this organization's still-pending sync rows. A failed revoke does not block the disconnect. `quickbooks_links` rows are kept so a later reconnect restores the mappings. Disconnect does not change the Intuit app or its webhook subscription.

Home currency comes from QuickBooks Preferences. The admin-only
`POST /quickbooks/int/v1/connection/test` endpoint also reads Preferences; the
console has no Test connection button. Tokens are refreshed before a sync,
import, webhook job, or connection test when they expire within 5 minutes. HTTP
401 during a sync first refreshes the token and retries that call once. The
console asks to connect QuickBooks again only when Intuit refuses the refresh
token (`invalid_grant`) or the new token is also refused. A refresh that fails
for another reason (for example a 503) does not ask to reconnect: the row that
hit it is retried later, the rest of that run is left pending, and the refresh
is not tried again until the next scheduled run (a Sync now or drain run does
not queue another run straight away). The same refresh-and-retry applies when
QuickBooks answers a batch with an authentication fault on every item instead of
HTTP 401; a batch where only some items were refused is not repeated, so the
accepted items are not written twice. A large batch is sent in chunks; when a
later chunk is refused, the earlier chunks' results are kept and the refused and
remaining items are retried on the next run. Copying QuickBooks customers on
connect runs only when Customers is enabled in Data Resolution. If Customers is
off, the import is skipped. An import does not run while a sync is running.

## Sync

`quickbooks:sync` stays registered on the Laravel scheduler, which the system
cron invokes every minute. There is no Enable schedule control. The form does
not send `sync.enabled`, and the command does not read a stored `enabled` flag,
so `enabled: false` does not stop a due sync. The command does not start when no
connection can be synced, or while a schedule hold is still in the future. It
queues an organization when the connection does not need reconnect, QuickBooks
is not rate limited, and either Sync frequency has elapsed with at least one due
pending row, or at least 20 pending rows are already due. The customer catalog
is the other reason a minute can start work. It is skipped when Customers is
off.

One flagged row does not start the next minute. Flagging clears a stored
schedule hold, so the next `schedule:run` may start the command, but the command
still waits until Sync frequency (`interval_minutes`, default 5) has elapsed
after `last_batch_at`, unless 20 or more rows are already due. A first sync,
with no `last_batch_at`, can start from one due row. After a run that queues
work, the command holds the next start for 60 seconds.

An organization with nothing due does not sync. The schedule does not read a stored batch size. Each run still stops after one page. That page is the stored `batch_size` when an older settings row has one, otherwise 100. The settings form does not send `batch_size`.

**Sync now** is on QuickBooks Setup. It uses the pending queue and does not load
the whole organization. It processes one pending page and does not drain the
rest of the queue. Leftover rows wait for the schedule above. Drain is chained
from a finished reconcile, and from a finished drain that still has due rows.
Sync now does not chain it. Reconcile is not a control on this screen. It covers
invoices already in this Fleetbase organization: a local non-draft invoice, or a
`quickbooks_links` row for this organization whose local type is invoice. It
does not list every invoice in the QuickBooks company.

QuickBooks update operations are posted in chunks of 20. Queries, creates, and voids are posted in chunks of at most 30.

Sync frequency is minutes (`interval_minutes`, default 5). Full sync frequency
(hours) (`periodic_interval_hours`, default 24) limits only the customer
catalog, and that catalog is skipped when Customers is unchecked. Customer
import reads QuickBooks in pages of at most 100 records and stores the next
start in `quickbooks_connections.customer_import_start`, so a run that stops at
the 600 second organization lock continues there.

Sync now with no connection returns HTTP 422 and does not save an activity row. The console shows that error. A stored realm that needs reconnect returns HTTP 422 and saves a skipped activity row.

Activity rows show created, updated, aligned, linked, skipped, unmatched, voided, and failed. Aligned counts inbound matches as well as outbound ones, including an inbound customer or invoice that already matched and was not written. A From QuickBooks run that changes nothing can show aligned. Linked is the customer-import count.

![QuickBooks Activity: one row per run with trigger, status, direction,
and counts; a failed run shows its error under the row][activity]

The Ledger dashboard widget is QuickBooks Sync. Its Sync now button requires `quickbooks reconcile sync` or an installation administrator, plus a connection and a saved Client ID and Client secret. It does not require a Redirect URI. OAuth connect still needs the public https redirect.

![QuickBooks Sync widget: Connected, last sync time, records waiting,
and Sync now][dashboard-widget]

## Webhooks

There is one public receiver: `POST /quickbooks/int/v1/webhooks`. It is outside
the session and does not use CSRF. Intuit signs the raw body. Intuit's
`intuit-signature` is checked against the install-wide system webhook verifier.
If that verifier is not saved, the webhook is rejected with HTTP 401. An
organization verifier and `QUICKBOOKS_WEBHOOK_VERIFIER` are not accepted.
Unsigned posts are HTTP 401 and do not dispatch events. A valid signature
returns HTTP 200 after events are dispatched. A valid signature for a realm with
no connection (for example after a disconnect), or for a body that names no
realm, also returns HTTP 200 and does nothing. The signature is checked before
the body is parsed. If processing fails, the request returns a 5xx and Intuit's
retry of the same body is processed. While a delivery is being processed its
body is remembered for 120 seconds only, so a worker that dies mid-request does
not block Intuit's retry; a repeat that arrives during processing gets HTTP 409,
so Intuit retries it. Once processed, an exact repeat is answered HTTP 200 and
not applied again for `quickbooks.webhook.max_age_seconds` (default 600). A
delivery whose event `time` (or `lastUpdated` in the older format) is older than
that age is answered HTTP 200 and not applied, whether or not its realm is
connected, and a warning is logged. These answer 200 because Intuit retries any
other answer for days and may hold back later deliveries until the first one is
acknowledged. The request itself does not call QuickBooks: payment lookups run
in the queued job `ResolveWebhookPayments`, so queue workers must be running.

Intuit sends a JSON array of CloudEvents. Each event's `type` names the entity
and event (for example `qbo.invoice.updated.v1`), `intuitentityid` is the
QuickBooks id, and `intuitaccountid` is the realm. Events `created`, `updated`,
and `deleted` are applied, `voided` is treated as a delete if Intuit sends it,
and `merged` is treated as an update of the surviving record (the merged-away id
in `data.deletedid` is not handled). Other events, such as `emailed`, and other
entities are ignored. The older `eventNotifications` body is still accepted.

After the signature check, each event is applied to Fleetbase organizations
connected to its realm. Direction `outbound` or `off` does not sync that type.
The event is still dispatched. This also applies to deletes and voids. A delete
or void in QuickBooks changes Fleetbase (void the invoice, remove the customer,
close and remove the wallet, unmark the paid invoice for a deleted payment) only
when the type is enabled, the direction takes inbound, and QuickBooks is primary
(or the direction is `inbound`). Otherwise Fleetbase keeps its record and the
deletion is ignored. A wallet whose balance is not zero is never closed from
QuickBooks, whether by a delete or by an account that is made inactive: it stays
open and linked, its pending sync is finished so the deleted account is not
reactivated at once, and a warning is logged with its id. A later change to that
wallet in Fleetbase pushes it again and makes the account active. Move the
balance out first if the wallet should close.

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

- **Currency.** An invoice whose currency differs from the QuickBooks home
  currency syncs only when QuickBooks multi-currency is enabled (Settings,
  Advanced, Currency). Otherwise the row fails with "Invoice currency EUR does
  not match the QuickBooks home currency USD." and nothing is sent. A wallet in
  another currency fails when it has no QuickBooks account yet and Fleetbase is
  Primary, whether or not multi-currency is on. With multi-currency on,
  QuickBooks applies its own exchange rate because no `ExchangeRate` is sent.
  Invoices that were posted in the home currency by an earlier version stay
  aligned until a push is attempted.
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

This package needs PHP `^8.2`, `fleetbase/core-api` `^1.6`, `fleetbase/fleetops-api` `^0.6.73`, and `fleetbase/ledger-api` `^0.0.13`. The Ember engine needs Node `>= 22`.

From the Fleetbase directory, install the extension from the Fleetbase registry:

```bash
flb install @unchartedwaters/quickbooks-engine
```

Pass `--path` with the Fleetbase directory when that directory is not the current one. `flb install` looks the package up on `https://api.fleetbase.io/~registry/v1/lookup` and installs `@unchartedwaters/quickbooks-engine` and `unchartedwaters/quickbooks-api`.

`.github/workflows/deploy.yml` publishes this package to the Fleetbase registry with `flb publish` when a `v*` tag is pushed. The tag has to contain that workflow, and the GitHub secrets named in it have to exist.

As an installation administrator, open Admin → QuickBooks → Settings and save
the Intuit Client ID and Client secret. Then open Organization settings →
QuickBooks Setup and connect.

### Server configuration

The `quickbooks.*` values in this guide, such as
`quickbooks.sync.claim_seconds` and `quickbooks.retention.attempt_days`, come
from the package's `server/config/quickbooks.php`. The package does not publish
that file. To change a value, add `config/quickbooks.php` to the Fleetbase API.
Laravel merges it with the package file only one level deep, so a section you
set (such as `sync` or `retention`) replaces that whole section. Copy every key
of the section from the package file, then change the one you need. If the API
caches its config, run `php artisan config:cache` again afterwards. A Docker
install that runs the stock API image loses files added inside the container,
so mount the file at `/fleetbase/api/config/quickbooks.php` in the
`application`, `queue`, and `scheduler` containers.

The Client secret, the webhook verifier, and each organization's QuickBooks
tokens are stored encrypted with the Fleetbase `APP_KEY`. Keep the same
`APP_KEY` on every API, queue, and scheduler container and across upgrades. If
it changes, those values can no longer be read: save the Client secret and the
webhook verifier again, and connect each organization again.

### Developer checkout

On this machine the repository is checked out at `/opt/fleetbase-quickbooks` on `main`, next to Fleetbase, not inside the Fleetbase tree. `application`, `queue`, and `scheduler` use the published `fleetbase/fleetbase-api:latest` image. That image does not contain this package. Do not build a custom API image for it. The running console bakes this engine into its image. A change in this checkout shows up in the API through the read-only mount. The console UI stays at that image build until the console image is rebuilt.

1. Check out this repository to `/opt/fleetbase-quickbooks` on `main`.
2. Point the Fleetbase app at that directory. Composer path repository `../../fleetbase-quickbooks`, console dependency `link:../../fleetbase-quickbooks`, and a read-only mount of `/opt/fleetbase-quickbooks` at `/fleetbase/packages/quickbooks`. A symlink at `packages/quickbooks` can point at the same checkout. These Fleetbase edits stay local and are not committed to `fleetbase/fleetbase`.
3. Put the existing Fleetbase `APP_KEY` in `api/.env`. Use the key that already decrypts this install.
4. Start the stack with Docker Compose. `docker-compose.yml` mounts `./api/.env` into `application`, `queue`, and `scheduler`, so all three read the same `APP_KEY`. It mounts `/opt/fleetbase-quickbooks` read-only at `/fleetbase/packages/quickbooks`. The entrypoint for those three services is `/fleetbase/packages/quickbooks/docker/ensure-quickbooks-extension.sh`. Console and API image builds take the package from a BuildKit context named `quickbooks`, not from a copy inside the Fleetbase tree.
5. On start, that script exits with an error if `/fleetbase/packages/quickbooks`
   is missing. It Composer-requires `unchartedwaters/quickbooks-api:0.0.2` when
   the provider is not installed, or when the mounted `composer.json` version or
   `require` entries differ from the installed package. It then runs
   `php artisan migrate --force` unless `QUICKBOOKS_SKIP_MIGRATE` is `1`.
   `queue` and `scheduler` set `QUICKBOOKS_SKIP_MIGRATE=1`, so only
   `application` migrates on startup. A later application start skips the
   require when the installed package still matches, and migrate applies only
   pending migrations.
6. Install console dependencies from `console/`. `console/package.json` links `@unchartedwaters/quickbooks-engine` to `../../fleetbase-quickbooks`. `console/fleetbase.config.json` lists `@unchartedwaters/quickbooks-engine` in `EXTENSIONS`. The running console bakes the engine into the image at `/usr/share/nginx/html/engines-dist/@unchartedwaters/quickbooks-engine`, from the BuildKit context named `quickbooks`. That container's bind is `console/fleetbase.config.json`.

### Developing locally

Starting OAuth needs a public https redirect URL. The redirect that Intuit receives is the `public_oauth_redirect_url` setting (Public OAuth Redirect URL on Admin → QuickBooks → Settings) when it is set, otherwise the computed callback on the API host. Either one must pass `Support/PublicHttps`: it has to be `https://`, and localhost, loopback, private, link-local, and other internal addresses are rejected, as is a hostname that does not resolve to a public address. A dev server on a private IP such as `10.30.0.34` therefore cannot connect as it is. Put a public https tunnel or reverse proxy in front of the API, save its callback URL (`https://<public host>/quickbooks/int/v1/oauth/callback`) as Public OAuth Redirect URL, and list the same URL under Redirect URIs in the Intuit app. Without a usable redirect, connect returns HTTP 422 and does not open Intuit.

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

`composer test:sandbox` runs live tests against a QuickBooks sandbox company.
CONTRIBUTING.md lists what they need.

Ember tests need Chrome. `testem.js` launches Chrome in development and in CI.

```bash
pnpm test:ember
```

`npm run test:ember` is the same command.

[admin-settings]: images/admin-settings.png
[setup-read-only]: images/setup-read-only.png
[setup-not-connected]: images/setup-not-connected.png
[setup-data-resolution]: images/setup-data-resolution.png
[setup-connected]: images/setup-connected.png
[setup-reconnect]: images/setup-reconnect.png
[activity]: images/activity.png
[dashboard-widget]: images/dashboard-widget.png
