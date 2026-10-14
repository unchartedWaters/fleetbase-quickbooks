# PR reviewer instructions

## Purpose

- Fleetbase extension that syncs customers, invoices, payments and wallets with
  QuickBooks Online: a Laravel API package (`unchartedwaters/quickbooks-api`,
  namespace `Fleetbase\Quickbooks`) plus an Ember engine
  (`@unchartedwaters/quickbooks-engine`).

## Architecture

- `QuickbooksServiceProvider` loads `server/src/routes.php`,
  `server/migrations`, observers and listeners, and schedules `quickbooks:sync`
  (every minute) and `quickbooks:prune` (daily). Defaults live in
  `server/config/quickbooks.php`.
- Outbound: observers on Fleetbase models flag rows in
  `quickbooks_pending_syncs`; jobs run `BatchRunner`/`SyncEngine` to push them.
  Rows are leased (`claimed_until`, `claimed_by`) so overlapping runs never send
  the same row twice.
- Inbound: `WebhookController` checks the Intuit signature against the
  install-wide verifier, then stale/replay checks, then queues work for every
  organization connected to that realm. `WebhookPayload` reads Intuit's
  CloudEvents array and the older `eventNotifications` body. Only a missing or
  bad signature gets 401; a signed stale or already processed delivery gets 200
  and is not applied, because Intuit retries any other answer for days.
- Global settings (installation admin only, `scope=admin`) are credentials and
  a sync template. An organization (`scope=company`, rows marked
  `scope: organization`) has its own complete copy of the sync options, made
  when it connects, saves or resets. Template edits must never change or queue
  an organization that has a copy. `SyncSettingsResolver` merges config
  defaults, global row, organization row.
- Authorization: controllers extend `QuickbooksController` and call
  `authorizeQuickbooks('quickbooks <action> <resource>')` or
  `authorizeInstallationAdmin()`. The company comes from the session; a request
  naming another `company_uuid` is refused.

## Folder structure

- `server/src/Services` – `SyncEngine`, `BatchRunner`, `FleetbaseDirectory`
  (most DB queries, links and leases), `CustomerImporter`, OAuth flow, token
  refresh, settings store, `QuickBooksClient`.
- `server/src/Support` – mappers, validators, gates, `SecretCipher`, `SafeLog`,
  `FlagGuard`, webhook helpers, `PublicHttps`, `SettingsKeys`.
- `server/src/Auth/Schemas` – permission schema read by
  `fleetbase:create-permissions`.
- `server/migrations` – timestamped migrations guarded with
  `hasTable`/`hasColumn`/index checks, each with `down()`.
- `server/tests` – Pest tests; `server/tests/Support` holds `FakeQuickBooks`,
  `MemorySettingsStore`, `InstallAdminRequest`.
- `scripts/` – `pest-runner.php` prepends `pest-bootstrap.php`, which loads stub
  Fleetbase/Laravel classes from `scripts/test-support/`.
- `addon/` – engine components, routes and `utils`; `tests/` – Ember QUnit
  tests; `translations/en-us.yaml` – UI strings.

## Stack

- PHP 8.2 (CI), Laravel via `fleetbase/core-api ^1.6`,
  `fleetbase/fleetops-api 0.6.71`, `fleetbase/ledger-api 0.0.12`. QuickBooks
  HTTP goes through Laravel's `Http` client in `QuickBooksClient`.
- Ember 4.8 engine (`ember-engines`, Glimmer), Node 22+, pnpm.
- Tooling: php-cs-fixer, PHPStan (level max), PHPMD (`phpmd.xml`), ESLint +
  Prettier, ember-template-lint, Stylelint; Codacy (`.codacy.yml`).

## Testing

- PHP: Pest 1 in `server/tests` (`composer test` = php-cs-fixer + PHPStan +
  Pest). Tests use `FakeQuickBooks` and `Http::swap`; no real Intuit calls. They
  run against stubs, not a real Fleetbase app, so code relying on new core-api
  behavior may need a stub in `scripts/test-support/`.
- Ember: QUnit unit and integration tests in `tests/` (`pnpm test:ember`, needs
  Chrome).
- Behavior changes and bug fixes need tests. Refactors keep the existing tests
  passing unchanged.
- Security- and tenancy-sensitive changes need explicit tests: company scoping,
  admin-only access, webhook signature/replay, secret redaction.

## Code style & conventions

- PHP: php-cs-fixer `@Symfony` plus aligned `=>`/`=`, single quotes, one space
  around `.`. Boolean checks are written `=== true`/`=== false`, not `!`.
- User-facing PHP text is a key in `translations/en-us.yaml`
  (`quickbooks.messages.*`, `quickbooks.validation.*`), read through
  `Support\Translation`. Flag hard-coded English in responses, validation
  errors, notifications or Activity errors. Text that is stored or thrown
  holds the key (`Translation::encode`) and is rendered where it reaches a
  reader (`Translation::get`/`render`). Never build a key or a message by
  concatenating English. Console output, logs and Intuit-facing webhook
  replies stay English.
- php-cs-fixer covers `server/src` and `server/tests`; PHPStan covers
  `server/src` only. New code must not add `phpstan-baseline.neon` entries.
- Codacy runs Lizard's 50-line function limit on `server/src`; split long
  methods rather than suppressing.
- JS: Prettier from the `prettier` key in `package.json` (4 spaces, width 190,
  single quotes in JS), ESLint `plugin:ember/recommended`.
- Behavior changes update `CONTRIBUTING.md` (Behavior) and `docs/SETUP.md`.

## PR-specific rules

- Branch from `main`; PRs target `main`. One concern per PR, minimum change.
- `package.json`, `composer.json` and `extension.json` versions must match
  (`check-versions.py`).
- The npm pack must keep `package.json`, `composer.json`, `extension.json`,
  `server/src/routes.php`, the service provider, `Auth/Schemas/Quickbooks.php`
  and `server/migrations/` (`check-registry-manifest.py`).
- Schema changes go in a new migration with idempotent `up()` and a `down()`; do
  not change the schema effect of an existing one.
- Permission changes in `Auth/Schemas` need
  `php artisan fleetbase:create-permissions` on existing installs; the PR must
  say so.

## Common pitfalls

- Tenant leaks: queries on links, pending rows, batches, connections and
  Fleetbase records must be scoped by `company_uuid`. Webhooks fan out per
  connected organization; never act across companies.
- Missing authorization: every protected endpoint needs an `authorize*` call.
  `v1/oauth/callback` and `v1/webhooks` are public (outside
  `fleetbase.protected`); treat any change there as security-sensitive.
- Settings scope: Client ID/secret, webhook verifier, environment, redirect and
  public URLs are global and admin-only. Flag any org-level endpoint, payload or
  UI that reads, returns or writes them. Organization rows may hold only
  `SettingsKeys::SYNC_FIELDS`.
- Secrets: OAuth tokens and secrets are encrypted at rest and never returned.
  Client secret and webhook verifier surface only as
  `client_secret_set`/`webhook_verifier_set`.
- Logging: context carries ids, exception class and code only, never tokens,
  payloads or DB error messages (they contain SQL and customer data). The
  `getMessage()` log in `FleetbaseDirectory` lease release is not a precedent.
- Observers and `Flag*` listeners must wrap work in `FlagGuard`; nothing may
  throw into a Fleetbase save.
- Outbound URLs and redirects must pass `PublicHttps` (no localhost, private,
  link-local or other non-public IPv4/IPv6).
- Sync correctness: respect row leases, link-first transactions,
  Primary/direction settings, and one QuickBooks Payment per invoice.
- A 401 gets one refresh and retry, then the connection is marked for reconnect;
  no unbounded retries.
- Gates: connect returns 422 without Client ID, secret and redirect. Sync,
  reconcile and import return 422 without a usable connection; with no realm no
  activity row is saved, a realm needing reconnect saves a skipped row.

## Out of scope

- Existing `phpstan-baseline.neon` entries and PHPMD rules excluded in
  `phpmd.xml` (`StaticAccess`, `ElseExpression`, `BooleanArgumentFlag`).
- Paths excluded in `.codacy.yml`: JSHint on Ember/JS code, Lizard on
  `server/tests` and `tests`, PHPMD on `server/tests` and
  `scripts/test-support`.
- Lockfile contents (`composer.lock`, `pnpm-lock.yaml`).
