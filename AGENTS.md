# packstub/credits

Credits and usage metering for a plain Laravel app: **Credits for Laravel**. A ledger of wallets, lots, holds, transactions and allocations; no Filament, no laravel/ai dependency. A Filament UI (customer usage page, operator ledger) belongs in a sibling `packstub/filament-credits`, the same split as Agents / Filament Agents.

## Commands

```bash
composer test               # Pest suite (Testbench, in-memory SQLite; the race test forks processes on a SQLite file)
composer test:filter <name>
composer lint               # Pint
```

## Layout

- `src/Support/Ledger` — every operation, each in one transaction under a lock on the wallet's row, after `catchUp()` (allowances, stale holds, due lots). Events are buffered and dispatched after the transaction; the events implement `ShouldDispatchAfterCommit`, so an app's outer transaction holds them too.
- `src/Support/Meters` (rates from config, remainders on the wallet), `src/Support/Allowance` (periods, keys, lazy grant through `Ledger::addLot()`).
- `src/Models` — `Wallet` (the public API delegates to the ledger), `Grant` (a lot), `Hold`, `Transaction` (append-only), `Allocation` (lot × transaction).
- `src/Commands` — `credits:audit` (read-only invariants), `credits:expire`.
- `CreditsManager` + `Facades\Credits` — wallets and the allowance resolver.
- `docs/` customer docs.

## Invariants (what `credits:audit` checks; every change must keep them)

- wallet `balance` = sum of its lots' `remaining` = sum of its transactions' `amount` = the last transaction's `balance_after`.
- wallet `held` = sum of pending holds = sum of `held_change` = the last `held_after`; `held` <= `balance`.
- a lot's `remaining` = sum of its allocations; a transaction's `amount` = sum of its allocations.
- Transactions are never updated or deleted.

## Conventions

- PHP 8.4+, Laravel 13. Every change needs a test and a `CHANGELOG.md` line. Integer credits only.
- Changelog headings are `## <version> — <date>`; the tag is `v<version>` on `main`.
- Migrations follow the workspace rule: additive, in the `create_*` migration and a guarded `add_*` one.
