# Auditing and concurrency

## credits:audit

```bash
php artisan credits:audit                 # every wallet
php artisan credits:audit --wallet=12     # one or more
```

Recomputes every cached number from the rows under it and exits non-zero on any drift, so it can run nightly with an alert or in CI against a copy of production. For each wallet:

- `balance` = the unspent credits of its lots, the sum of its ledger, and the last line's `balance_after`;
- `held` = its pending holds, the sum of the ledger's `held_change`, and the last line's `held_after`;
- `held` <= `balance`;
- each lot's `remaining` = the sum of the allocations that filled and drained it;
- each transaction's `amount` = the sum of its allocations.

It only reads. A row edited by hand shows up as the checks it broke.

## Concurrency

Every operation runs in one database transaction and locks the wallet's row first (`SELECT … FOR UPDATE`), so two workers spending from one wallet take turns and the second sees the first's spend. The suite forks 20 processes that each try to spend 10 credits from a wallet of 100 and checks that exactly 10 succeed, 10 are refused and the books balance.

Operations nest inside your own transactions; events wait for your commit.

## SQLite

SQLite has no row locks. Its default `DEFERRED` transactions let two processes read the same balance and fail one of them with "database is locked" when it writes: no credit is spent twice, but a spend the wallet could afford is lost (in the suite's race, 15 of 20 processes fail this way). Use `IMMEDIATE`, which takes the write lock when the transaction begins:

```php
// config/database.php
'sqlite' => [
    // …
    'busy_timeout' => 10_000,
    'transaction_mode' => 'IMMEDIATE',
],
```
