# Security policy

Credits for Laravel keeps the books your customers pay against: how many credits a wallet has, which of them a hold reserves, and what every charge took. A bug that lets a wallet spend credits it does not have, mints credits no lot backs, or lets one holder touch another's wallet is a security issue to us, and we ask that you report it privately so a fix can ship before the details are public.

## Supported versions

Security fixes are released for the latest minor version of each supported release line.

| Version | Supported |
| ------- | --------- |
| 1.x     | Yes       |

## Reporting a vulnerability

Email **security@packstub.dev**. Do not open a public GitHub issue, discussion or pull request for a security problem: a public report puts every installation at risk before a fix exists.

Include what you can:

- The package version (`composer show packstub/credits`), PHP version and database (the ledger relies on row locks, which SQLite, MySQL, MariaDB and Postgres take differently).
- A description of the issue and its impact, ideally a minimal reproduction, or the output of `php artisan credits:audit` if the books no longer balance.

What to expect:

- **Acknowledgment within 48 hours**, from a human.
- Updates while we validate, fix and release.
- **Coordinated disclosure**: we ask that you keep the details private until a patched release is on Packagist. We credit you in the release notes unless you prefer otherwise.

## Scope

Reports in these areas are treated as **highest priority**:

- **Double spending**: two concurrent operations (spends, holds, captures, transfers, allowance grants) that together take more than the wallet had, on any supported database.
- **Minted credits**: any input to `grant`, `spend`, `hold`, `settle`, `settleUsage`, `meter`, `refund`, `transfer` or `adjust` that leaves a wallet with credits no lot backs, or a lot with more than it was granted. `credits:audit` turning red after a sequence of public API calls is a report by itself.
- **Idempotency**: a repeated key that charges twice, or a key that replays another request's result.
- **Holds and expiry**: a settle or release that frees credits a hold still reserves, an expiry that eats credits a hold reserved, or a hold settled more than once.
- **Cross-wallet effects**: an operation on one wallet that changes another's balance, lots or holds, other than a `transfer` between the two.

Also in scope: the artisan commands (`credits:audit`, `credits:expire`), the events and what they carry, and the migrations.

Out of scope: vulnerabilities in Laravel itself (report them upstream; we still ship a mitigation when the exposure is specific to this package), issues that need an already-compromised server or database, and missing authorization in your own application code (see below).

## Hardening guidance for integrators

The package knows nothing about your users: it trusts every call it receives. Authorization is yours, at the point where a request meets a wallet.

**Resolve wallets from the authenticated holder, never from an id in the request.** `$request->user()->credits()` or `$team->credits()` after your own membership check. A `Wallet::find($request->wallet_id)` lets any user act on any wallet.

**Scope holds and transactions to that wallet.** `settle()`, `capture()`, `release()` and `extend()` act on whichever hold they are given, so look a hold up through the wallet (`$wallet->holds()->pending()->findOrFail($id)`), not with `Hold::findOrFail($id)`. `refund()` refuses a transaction of another wallet, but still pass it a line found through `$wallet->transactions()`.

**Treat amounts and usage from a request as input.** The package refuses negative, non-numeric, non-finite and out-of-range amounts and quantities, but it charges what it is told: a user who can choose the quantity passed to `meter()` or `settleUsage()` can choose to pay nothing. Take quantities from the provider's response (token usage, bytes processed), never from the client.

**Keep `grant()` and `adjust()` behind operator or payment code.** A grant is money; call it from a verified webhook (with the payment id as the idempotency key, see `docs/selling-credits.md`) or an operator action with its own authorization.

**Never write the ledger tables yourself.** The models are unguarded for the ledger's own use; `Wallet::create($request->all())`, a raw update of `balance` or `held`, or a deleted transaction breaks the invariants `credits:audit` checks. Treat a red audit as an incident, not as noise.

**Use a database that honours row locks in production.** The ledger serialises operations on a wallet with `SELECT … FOR UPDATE`. SQLite has no row locks; its single writer still prevents double spending, but set `transaction_mode => IMMEDIATE` (see `docs/auditing.md`) so racing writers wait instead of failing.

If you believe you have found a way to reach any of the outcomes under Scope through the package's own code, report it: that is exactly the class of issue we most want to hear about.
