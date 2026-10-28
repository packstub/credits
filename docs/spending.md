# Wallets, lots and spending

A wallet has two numbers. `balance` is every unexpired credit. `held` is what pending holds reserve of it. What can be spent is the difference:

```php
$wallet = $team->credits();

$wallet->available();      // balance - held, after catching up
$wallet->total();          // balance, held credits included
$wallet->canSpend(25);
```

Never write the columns yourself: every change goes through the ledger, in one transaction, under a lock on the wallet's row.

## Grants and lots

Every grant is a lot with its own source, reason and expiry:

```php
$wallet->grant(500, 'Top-up', source: 'top-up');                               // never expires
$wallet->grant(1_000, 'October plan', expiresAt: now()->endOfMonth(), source: 'plan');
$wallet->grant(50, 'Welcome bonus', expiresAt: now()->addDays(14), source: 'promo');
```

Spends take from the lot that expires soonest, then the next, and from never-expiring lots last (oldest first). With the three lots above, a spend of 80 takes the 50 bonus credits and 30 plan credits; the top-up stays untouched. Due lots expire the next time the wallet is used, with an `expire` line on the ledger.

## Spending

```php
$wallet->spend(25, 'PDF export', key: "export:{$export->id}");
```

A spend larger than what is available throws `InsufficientCredits` (`requested`, `available`, `missing()`) and changes nothing.

## Idempotency keys

Every operation that charges or grants takes a `key`. The same key again returns the transaction it produced the first time instead of charging twice; ideal for queued jobs, webhooks and retries:

```php
$wallet->grant(1_000, 'Top-up', key: "stripe:{$paymentIntent->id}");
```

A key reused for a *different* request (another type or amount) throws `IdempotencyConflict`: two operations sharing a key is a bug worth hearing about. Keys are unique per wallet.

## Refunds

```php
$spend = $wallet->spend(30, 'Export');

$wallet->refund($spend);                 // all of it
$wallet->refund($spend, 10, 'Partial');  // or part
$spend->refundable();                    // what is left to refund
```

A refund goes back to the lots the credits came from, keeping their expiry. Partial refunds unwind the spend from its end, so two of them never return the same credits. Credits whose lot expired in the meantime come back as a new lot that lives `refund_grace_days`. Spends and captures can be refunded; refunding more than is left throws `InvalidAmount`.

## Transfers

Move credits from one wallet to another: a team's pool to a member, one holder to another.

```php
$team->credits()->transfer($member->credits(), 500, 'October share', key: "share:{$member->id}:2026-10");
```

Both wallets are locked in one database transaction, so the credits leave one and arrive in the other or neither happens. The credits keep their expiry: the receiver gets one lot per expiry date they had (source `transfer`), so a transfer never stretches credits that were about to expire. Credits a hold reserves cannot be moved, and a transfer larger than what is available throws `InsufficientCredits`.

`transfer()` returns the sender's line (type `transfer`, negative, `meta.to_wallet`); the receiver's line is positive, has `meta.from_wallet` and points back to it as its `parent`. The key belongs to the sender's wallet, and replays only for the same receiver and amount.

## Adjustments

An operator's correction, with a required reason:

```php
$wallet->adjust(100, 'Goodwill after the outage');                                 // adds a never-expiring lot
$wallet->adjust(50, 'Make-good for the outage', expiresAt: now()->addDays(30));    // or one that expires
$wallet->adjust(-20, 'Duplicate top-up');                                          // takes like a spend
```

## The ledger

Every change is a `Transaction`, never updated or deleted (the model throws if you try): `type` (grant, spend, hold, capture, release, expire, refund, adjust, transfer), `amount` (its effect on the balance, negative takes), `held_change`, `balance_after`, `held_after`, `reason`, `meta`, and its `allocations`, the lots it moved. A statement prints from the rows alone:

```php
use Packstub\Credits\Enums\TransactionType;

$wallet->transactions()->latest('id')->paginate();
$wallet->transactions()->ofType(TransactionType::Spend, TransactionType::Capture)->between(now()->startOfMonth())->get();
```

`between($from, $to)` includes `$from` and excludes `$to`; either end may be `null`.

## Reporting

| Method | Returns |
| --- | --- |
| `expiring($by)` | credits that expire by that moment unless spent first: "120 credits expire on 1 November" |
| `balanceAt($moment)` | the balance right after the last line written at or before it |
| `spent($from, $to)` | credits charged for usage (spends and captures) less their refunds, in the window |
| `usage($from, $to)` | metered usage per meter in the window: `['ai.input_tokens' => 1_204_331, 'ai.output_tokens' => 88_120]` |

The lots themselves are `$wallet->grants()`; `active()` keeps those with credits left that have not expired, `spendable()` orders them the way spends take from them:

```php
$wallet->grants()->active()->whereNotNull('expires_at')->orderBy('expires_at')->first();   // the next lot to expire
```

## Exceptions

Every exception the package throws implements `Packstub\Credits\Exceptions\CreditsException`, so one `catch` can handle them all:

| Exception | Thrown when |
| --- | --- |
| `InsufficientCredits` | a spend, hold, strict capture or transfer needs more than is available (`requested`, `available`, `missing()`) |
| `InvalidAmount` | a zero or negative amount, negative or missing usage, a refund of the wrong transaction or of too much, a hold with no lifetime, a transfer to the same wallet |
| `IdempotencyConflict` | a key reused for a different request (`key`, `existing`) |
| `HoldNotPending` | settling, capturing, releasing or extending a hold that is over (`hold`) |
| `UnknownMeter` | usage for a meter without a rate, or with a rate that is not whole numbers (`meter`) |

## Events

All are dispatched after the database commits, so a listener never sees a balance that was rolled back, and none fire for an operation that failed.

| Event | When |
| --- | --- |
| `TransactionRecorded` | every ledger line (`wallet`, `transaction`); a transfer records one on each wallet |
| `BalanceLow` | available credits crossed below the threshold (`available`, `threshold`); once per crossing |
| `Shortfall` | usage cost more than the wallet could cover (`amount`, `hold` or null); the work happened, so bill it, cap the customer or alert someone |
