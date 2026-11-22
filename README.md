# Fleetbase QuickBooks Extension
[![Codacy Badge](https://app.codacy.com/project/badge/Grade/1d28a0f1672c4fe2af45ced113b3c176)](https://app.codacy.com/gh/unchartedWaters/fleetbase-quickbooks/dashboard?utm_source=gh&utm_medium=referral&utm_content=&utm_campaign=Badge_grade)

QuickBooks is a [Fleetbase](https://www.fleetbase.io) extension that syncs
customers, invoices, payments, wallets, and products with QuickBooks Online.

## What syncs

- Customers
- Invoices
- Payments
- Wallets (QuickBooks accounts)
- Products (Storefront products and Fleet-Ops fees, to QuickBooks only)

## Requirements

This extension works only with **Fleetbase 0.8.1 or later**. Fleetbase 0.8.0
and earlier, including 0.7.x and `core-api` 1.x, are not supported.

- Fleetbase 0.8.1 or later (`fleetbase/core-api` `^2.0`,
  `fleetbase/fleetops-api` `^0.7.1`, `fleetbase/ledger-api` `^0.1.0`)
- PHP `^8.2` on the API, Node `>= 22` for the console build
- A [QuickBooks app](docs/SETUP.md#create-a-quickbooks-app) on the Intuit
  Developer Portal
- A public https address for the console and the API, which Intuit's sign-in
  and webhooks need

## Install

1. From the Fleetbase directory, install the extension from the Fleetbase
   registry. Pass `--path` when that directory is not the current one.

   ```bash
   flb install @unchartedwaters/fleetbase-quickbooks
   ```

   This adds the Composer package `unchartedwaters/quickbooks-api` and the npm
   package `@unchartedwaters/fleetbase-quickbooks`. To install from a checkout
   instead, see
   [Install from a checkout](docs/SETUP.md#install-from-a-checkout).

2. `flb install` only adds the packages. From the Fleetbase API directory, run
   `php artisan migrate --force` and `php artisan fleetbase:create-permissions`
   (both are part of Fleetbase's `api/deploy.sh`).
3. Rebuild or restart the console so it includes the engine.
4. Make sure queue workers and the Laravel scheduler (cron every minute) run,
   and set `CONSOLE_HOST` on the API to the console address.

## Set up

1. Create the QuickBooks app on the Intuit Developer Portal. Intuit's guide is
   [Create and start developing your app](https://developer.intuit.com/app/developer/qbo/docs/get-started/start-developing-your-app),
   and [docs/SETUP.md](docs/SETUP.md#create-a-quickbooks-app) lists the app
   settings the extension needs.
2. As an installation administrator, open Admin → QuickBooks → Settings and
   save the Intuit Client ID and Client secret.
3. As an organization administrator, or a user with the QuickBooks
   Administrator or QuickBooks Operator role, open Organization settings →
   QuickBooks Setup and connect. See
   [Permissions](docs/SETUP.md#permissions).

The rest of setup is in [docs/SETUP.md](docs/SETUP.md).

## Documentation

Setup, permissions, sync behavior, data resolution, and publishing are in [docs/SETUP.md](docs/SETUP.md).
