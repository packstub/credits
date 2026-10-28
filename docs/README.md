# Credits for Laravel

## Features

- **[Holds for AI calls](holds.md)**: reserve an estimate before the call, settle what it cost after; a dead worker leaks nothing.
- **[Credits that expire, spent in order](spending.md#grants-and-lots)**: every grant is a lot with its own expiry, taken soonest-expiring first.
- **[Meters with exact fractions](meters.md#exact-fractions)**: 400 tokens at 1 credit per 1,000 charge nothing yet; the remainder carries to the next call.
- **[Plan allowances without a cron](allowances.md)**: granted once per period on first use, however many workers race; anchored or calendar.
- **[Idempotency keys](spending.md#idempotency-keys)**: a retried job or webhook never charges twice; a reused key for a different request is an error.
- **[Refunds and transfers](spending.md#refunds)**: credits go back to the lots they came from, or move to another wallet, keeping their expiry.
- **[Upgrades, rollover, rates per plan](allowances.md)**: a bigger plan grants the difference at once; quotes before charging.
- **[Reporting from the ledger](spending.md#reporting)**: what expires when, the balance at any moment, credits spent and usage per period.
- **[Books that balance](auditing.md)**: append-only lines, row locks, and `credits:audit` to prove every cached number from the rows under it.

Integer credits throughout (pick your scale: 1 credit = 1/1000 of a cent works), no floats, SQLite, MySQL, MariaDB and Postgres.

## Guides

- [Installation and configuration](installation.md)
- [Wallets, lots and spending](spending.md)
- [Holds and AI calls](holds.md)
- [Meters](meters.md)
- [Allowances](allowances.md)
- [Selling credits](selling-credits.md)
- [Auditing and concurrency](auditing.md)
