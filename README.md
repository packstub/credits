# Credits for Laravel

Credits and usage metering for Laravel apps that charge per use: AI features, exports, SMS, API calls. Give each user or team a balance, let plans top it up every month, charge measured usage, and know, at any time, where every credit came from and where it went.

```php
$team->credits()->grant(1_000, 'Pro plan', expiresAt: now()->endOfMonth());

$hold = $team->credits()->hold(200, 'AI turn');          // reserve before the call
$response = $agent->prompt($question);
$hold->settleUsage([                                      // charge what it actually cost
    'ai.input_tokens' => $response->usage->uncachedInputTokens(),
    'ai.output_tokens' => $response->usage->outputTokens,
]);

$team->credits()->available();                            // whole credits; a fraction carries to the next call
```

## What it does

Charging per use, AI above all, needs more than a balance and its history:

- **Holds.** The cost of an AI call is known only after it ran. Reserve an estimate first, so two parallel calls cannot spend the same credits, then settle the real cost or release the hold. A worker that dies mid-call does not leak credits: the hold expires and gives them back. Work that ran past its hold still settles.
- **Credits that expire, spent in the right order.** Every grant is a lot with its own expiry. Spends take from the lots that expire soonest, then from those that never do, so customers never lose credits they could have used.
- **Meters that never round a credit away.** 400 tokens at 1 credit per 1,000 charge nothing now; the fraction is carried on the wallet and the third such call charges 1. Thirty-seven small calls cost exactly what one call of their total would.
- **Plan allowances without a cron.** `Allowance::monthly(1_000)` is granted the first time a wallet is touched in a period, once, however many workers race for it. Anchored to a subscription's start, it renews on the 28th when the 31st does not exist.
- **Idempotency keys** on every operation, so a retried job or webhook never charges twice, and a key reused for a different request is an error instead of a silent no-op.
- **Refunds back to the original lots**, keeping their expiry; partial refunds unwind from the end so no credit returns twice.
- **Books that prove they balance.** `php artisan credits:audit` recomputes every cached number from the rows under it and exits non-zero on drift.
- **Safe under concurrency.** Every operation runs in one transaction under a row lock. The suite forks 20 processes that race for one wallet and checks that exactly the affordable spends go through.

Integer credits throughout (pick your scale: 1 credit = 1/1000 of a cent works), no floats, Laravel 13, PHP 8.4+, SQLite, MySQL, MariaDB and Postgres.

## Installation

```bash
composer require packstub/credits
php artisan migrate
```

Add the trait to whatever holds credits:

```php
use Packstub\Credits\Concerns\HasCredits;

class Team extends Model
{
    use HasCredits;
}
```

Schedule the catch-up for idle wallets (optional: every wallet catches up when it is used):

```php
Schedule::command('credits:expire')->hourly();
```

## Documentation

- [Installation and configuration](docs/installation.md)
- [Wallets, lots and spending](docs/spending.md): grants, expiry, idempotency, refunds, adjustments, events
- [Holds and AI calls](docs/holds.md): reserve, settle, release, late settles, a laravel/ai recipe
- [Meters](docs/meters.md): usage priced in credits with exact fractions
- [Allowances](docs/allowances.md): plan credits every period, anchored or calendar, rollover
- [Auditing and concurrency](docs/auditing.md): `credits:audit`, row locks, SQLite in production

## Testing

```bash
composer test
```

## License

MIT. See [LICENSE.md](LICENSE.md).
