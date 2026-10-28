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

Without the trait: `Credits::wallet($team, 'default')`. The holder must be saved first; a wallet for an unsaved model throws.

Holders keyed by a UUID or a ULID (`HasUuids`, `HasUlids`) work too: set `CREDITS_HOLDER_KEY_TYPE=uuid` (or `ulid`) **before** the first migration, so `credit_wallets.holder_id` is created as that type instead of a big integer. All holders of one app share the type; changing it later needs a migration of your own on `credit_wallets`.

## Configuration

```bash
php artisan vendor:publish --tag=packstub-credits-config
```

| Key | Env | Default | What |
| --- | --- | --- | --- |
| `holder_key_type` | `CREDITS_HOLDER_KEY_TYPE` | `int` | Primary key type of the models that hold credits: `int`, `uuid` or `ulid`. Read when the tables are created. |
| `meters` | | `[]` | Rates per meter: `'ai.output_tokens' => ['credits' => 5, 'per' => 1_000]`. Different rates per plan with `Credits::metersUsing()`. See [Meters](meters.md). |
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
