# Changelog

All notable changes to `packstub/credits` are documented here.

## 1.0.0 — 2026-10-28

### Security

- Numbers past the 64-bit range are refused with `InvalidAmount` instead of wrapping: a metered quantity (or its price, or the carried remainder) too large for an integer used to come out negative on PHP 8.4 and be recorded as a charge below zero, which added credits no lot backed; a grant, refund or transfer that would take a balance past the limit now throws before writing anything. Found by the pre-release audit (`tests/Security`); no released version was affected.

### Added

- Holders keyed by a UUID or a ULID: `holder_key_type` (`CREDITS_HOLDER_KEY_TYPE`, `int` by default) decides how the migration creates `credit_wallets.holder_id`.
- Idempotency keys, reasons, sources and wallet names longer than their columns (255 characters), an empty wallet name, a hold lifetime past a century and `adjust(PHP_INT_MIN)` are refused up front, before the lock, instead of failing in the database.
- Wallets per holder and name (`HasCredits`, `Credits::wallet()`), with `available()`, `total()` and `canSpend()`.
- Lots: grants with a source, a reason and an expiry, spent soonest-expiring first, expired on the wallet's next use or by `credits:expire`.
- Holds: `hold()`, then `settle()`, `capture()`, `settleUsage()` or `release()`; `extend()` for work still running; expired holds give their credits back and still settle late. A hold reserves the soonest-expiring credits: they outlive their lot until the hold is settled, the capture is charged from them and what it gives back expires at once, so credits bought meanwhile are never used up in their place.
- `Hold::pending()` scope, `isSettled()`, `transactions` and `settlement` relations.
- Meters priced in config, with exact fractions carried per wallet and per meter; shortfalls recorded instead of failing for usage that already happened. Negative, missing or non-numeric quantities and malformed rates are refused.
- Rates per holder over the configured ones (`Credits::metersUsing()`), and `quote()` for what usage would cost without charging it.
- Allowances (`Credits::allowancesUsing()`, `Allowance::monthly()` and friends), granted lazily once per period, calendar or anchored (nothing before the anchor). An allowance that grows mid-period grants the difference at once; rollover for good or for a number of periods (`rollover(2)`); two allowances under one name are refused.
- Transfers between wallets (`transfer()`), both locked in one transaction in id order, the credits keeping their expiry.
- Idempotency keys on every operation; `IdempotencyConflict` for a key reused for a different request.
- Refunds back to the original lots, partial refunds unwinding from the end, a grace lot for credits whose lot expired.
- Operator adjustments with a reason, optionally expiring.
- Reporting from the ledger: `expiring()`, `balanceAt()`, `spent()`, `usage()`, and the `ofType()` / `between()` scopes on transactions; `active()` and `due()` scopes on lots.
- Balance reads (`available()`, `total()`) take no lock when nothing is due.
- Append-only transactions and allocations: the models refuse updates and deletes.
- `CreditsException`, implemented by every exception the package throws.
- Events after commit: `TransactionRecorded`, `BalanceLow` (once per crossing), `Shortfall`.
- `credits:audit`, which recomputes every cached number from the rows under it, checks every lot stays within its amount, and exits non-zero on drift.
- `credits:expire` counts only the wallets it changed and reports a failing wallet without stopping.
- Docs: selling credits with Cashier (Stripe, Paddle).
