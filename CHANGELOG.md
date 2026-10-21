# Changelog

All notable changes to `packstub/credits` are documented here.

## Unreleased

### Added

- Wallets per holder and name (`HasCredits`, `Credits::wallet()`), with `available()`, `total()` and `canSpend()`.
- Lots: grants with a source, a reason and an expiry, spent soonest-expiring first, expired on the wallet's next use or by `credits:expire`.
- Holds: `hold()`, then `settle()`, `capture()`, `settleUsage()` or `release()`; expired holds give their credits back and still settle late; reserved credits outlive their lot until the hold is settled.
- Meters priced in config, with exact fractions carried per wallet and per meter; shortfalls recorded instead of failing for usage that already happened.
- Allowances (`Credits::allowancesUsing()`, `Allowance::monthly()` and friends), granted lazily once per period, calendar or anchored, with rollover.
- Idempotency keys on every operation; `IdempotencyConflict` for a key reused for a different request.
- Refunds back to the original lots, partial refunds unwinding from the end, a grace lot for credits whose lot expired.
- Operator adjustments with a reason.
- Events after commit: `TransactionRecorded`, `BalanceLow` (once per crossing), `Shortfall`.
- `credits:audit`, which recomputes every cached number from the rows under it and exits non-zero on drift.
