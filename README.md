# Fleetbase QuickBooks Extension
[![Codacy Badge](https://app.codacy.com/project/badge/Grade/1d28a0f1672c4fe2af45ced113b3c176)](https://app.codacy.com/gh/unchartedWaters/fleetbase-quickbooks/dashboard?utm_source=gh&utm_medium=referral&utm_content=&utm_campaign=Badge_grade)

QuickBooks is a [Fleetbase](https://www.fleetbase.io) extension that syncs customers, invoices, payments, and wallets with QuickBooks Online.

## What syncs

- Customers
- Invoices
- Payments
- Wallets (QuickBooks accounts)

## Quick start

From the Fleetbase directory, install the Fleetbase QuickBooks extension from the Fleetbase registry:

```bash
flb install @unchartedwaters/quickbooks-engine
```

As an installation administrator, open Admin → QuickBooks → Settings and save the Intuit Client ID and Client secret. Then open Organization settings → Quickbooks Setup and connect.

The rest of setup is in [docs/SETUP.md](docs/SETUP.md).

## Documentation

Setup, permissions, sync behavior, data resolution, and publishing are in [docs/SETUP.md](docs/SETUP.md).
