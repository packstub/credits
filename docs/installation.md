# Installation and configuration

```bash
composer require packstub/credits
php artisan migrate
```

The migration creates five tables: `credit_wallets` (a holder's balance), `credit_grants` (the lots), `credit_holds`, `credit_transactions` (the ledger, append-only) and `credit_allocations` (which lots each transaction moved). Publish it with `php artisan vendor:publish --tag=packstub-credits-migrations` to change it before running.

## The holder

Any Eloquent model can hold credits. Add the trait:

```php
use Packstub\Credits\Concerns\HasCredits;

class Team extends Model
{
    use HasCredits;
}

$team->credits();          // the "default" wallet, created on first use
$team->credits('sms');     // a second, separate balance
$team->creditWallets;      // all of them
```

Without the trait: `Credits::wallet($team, 'default')`.

## Configuration

```bash
php artisan vendor:publish --tag=packstub-credits-config
```

| Key | Env | Default | What |
| --- | --- | --- | --- |
| `meters` | | `[]` | Rates per meter: `'ai.output_tokens' => ['credits' => 5, 'per' => 1_000]`. See [Meters](meters.md). |
| `hold_ttl` | `CREDITS_HOLD_TTL` | `900` | Seconds a hold lives when placed without a `ttl`. |
| `refund_grace_days` | `CREDITS_REFUND_GRACE_DAYS` | `7` | Lifetime of the new lot a refund creates for credits whose lot expired meanwhile. |
| `low_balance_threshold` | `CREDITS_LOW_BALANCE_THRESHOLD` | `null` | `BalanceLow` fires when available credits fall below it. A wallet's own `low_balance_threshold` column wins. |

## The scheduler

Nothing needs a cron to be correct: every operation first expires due lots, releases stale holds and grants the current allowances of its wallet. Schedule `credits:expire` so wallets nobody touches still expire on time and their events fire:

```php
Schedule::command('credits:expire')->hourly();
```

## SQLite in production

Set `'transaction_mode' => 'IMMEDIATE'` on the SQLite connection. See [Auditing and concurrency](auditing.md#sqlite).
