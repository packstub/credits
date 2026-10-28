# Credits for Laravel

<div class="filament-hidden">

[![Latest Version on Packagist](https://img.shields.io/packagist/v/packstub/credits.svg?style=flat-square)](https://packagist.org/packages/packstub/credits)
[![Tests](https://img.shields.io/github/actions/workflow/status/packstub/credits/tests.yml?branch=main&label=tests&style=flat-square)](https://github.com/packstub/credits/actions/workflows/tests.yml)
[![Total Downloads](https://img.shields.io/packagist/dt/packstub/credits.svg?style=flat-square)](https://packagist.org/packages/packstub/credits)
[![License](https://img.shields.io/packagist/l/packstub/credits.svg?style=flat-square)](https://github.com/packstub/credits/blob/main/LICENSE.md)
[![Sponsor](https://img.shields.io/badge/sponsor-%E2%9D%A4-ea4aaa?style=flat-square&logo=githubsponsors&logoColor=white)](https://github.com/sponsors/icaliman)

![Credits for Laravel: a Usage & credits page with the available, held and expiring balances, the lots in the order spends take them, and the ledger with a grant, a spend, its refund, a metered capture and a pending hold](https://raw.githubusercontent.com/packstub/art/main/credits/banner.jpg)

</div>

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

## Features

- **[Holds for AI calls](https://packstub.dev/docs/credits/holds)**: reserve an estimate before the call, settle what it cost after; a dead worker leaks nothing.
- **[Credits that expire, spent in order](https://packstub.dev/docs/credits/spending#grants-and-lots)**: every grant is a lot with its own expiry, taken soonest-expiring first.
- **[Meters with exact fractions](https://packstub.dev/docs/credits/meters#exact-fractions)**: 400 tokens at 1 credit per 1,000 charge nothing yet; the remainder carries to the next call.
- **[Plan allowances without a cron](https://packstub.dev/docs/credits/allowances)**: granted once per period on first use, however many workers race; anchored or calendar.
- **[Idempotency keys](https://packstub.dev/docs/credits/spending#idempotency-keys)**: a retried job or webhook never charges twice; a reused key for a different request is an error.
- **[Refunds and transfers](https://packstub.dev/docs/credits/spending#refunds)**: credits go back to the lots they came from, or move to another wallet, keeping their expiry.
- **[Upgrades, rollover, rates per plan](https://packstub.dev/docs/credits/allowances)**: a bigger plan grants the difference at once; quotes before charging.
- **[Reporting from the ledger](https://packstub.dev/docs/credits/spending#reporting)**: what expires when, the balance at any moment, credits spent and usage per period.
- **[Books that balance](https://packstub.dev/docs/credits/auditing)**: append-only lines, row locks, and `credits:audit` to prove every cached number from the rows under it.

Integer credits throughout (pick your scale: 1 credit = 1/1000 of a cent works), no floats, SQLite, MySQL, MariaDB and Postgres.

## Compatibility

| Credits | Laravel | PHP |
| --- | --- | --- |
| 1.x | 13.x | 8.4+ |

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
- [Wallets, lots and spending](docs/spending.md): grants, expiry, idempotency, refunds, transfers, adjustments, reporting, events, exceptions
- [Holds and AI calls](docs/holds.md): reserve, settle, release, extend, late settles, expiring credits, a laravel/ai recipe
- [Meters](docs/meters.md): usage priced in credits with exact fractions, quotes, rates per plan
- [Allowances](docs/allowances.md): plan credits every period, anchored or calendar, upgrades, rollover
- [Selling credits](docs/selling-credits.md): top-ups with Cashier (Stripe, Paddle), plan credits, refunded payments
- [Auditing and concurrency](docs/auditing.md): `credits:audit`, row locks, SQLite in production

## Testing

```bash
composer test
```

## Security

Report vulnerabilities to security@packstub.dev, not through a public issue: see [SECURITY.md](SECURITY.md), which also lists what to keep on your side of the line (wallets from the authenticated holder, holds and transactions looked up through the wallet, quantities from the provider's response).

## License

MIT. See [LICENSE.md](LICENSE.md).
