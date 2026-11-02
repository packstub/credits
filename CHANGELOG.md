# Changelog

All notable changes to `packstub/credits` are documented here.

## 1.0.0 — 2026-10-28

First release of Credits for Laravel: credits and usage metering for your app, kept in an append-only ledger. Requires PHP 8.4 and Laravel 13.

- Wallets per holder and name, with lots that expire and are spent soonest-expiring first
- Holds that reserve credits up front (before an AI call, say) and settle from what was used
- Meters priced in config, with exact fractions, per-holder rates and `quote()`
- Monthly allowances granted lazily, no cron needed, with rollover
- Transfers, refunds, operator adjustments and idempotency keys on every operation
- `credits:audit` checks every cached balance against the ledger; a guide covers selling credits with Cashier
- Security: amounts past the 64-bit integer range are refused instead of wrapping into negative charges

Docs: https://packstub.dev/docs/credits
