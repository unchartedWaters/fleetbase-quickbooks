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

## Quick start

From the Fleetbase directory, install the Fleetbase QuickBooks extension from the Fleetbase registry:

```bash
flb install @unchartedwaters/quickbooks-engine
```

You also need a QuickBooks app on the Intuit Developer Portal. Intuit's guide is
[Create and start developing your app](https://developer.intuit.com/app/developer/qbo/docs/get-started/start-developing-your-app),
and [docs/SETUP.md](docs/SETUP.md#create-a-quickbooks-app) lists the app
settings the extension needs.

As an installation administrator, open Admin → QuickBooks → Settings and save
the Intuit Client ID and Client secret. Then open Organization settings →
QuickBooks Setup and connect.

The rest of setup is in [docs/SETUP.md](docs/SETUP.md).

## Documentation

Setup, permissions, sync behavior, data resolution, and publishing are in [docs/SETUP.md](docs/SETUP.md).
