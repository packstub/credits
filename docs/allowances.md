# Allowances

Credits a plan gives every period. Tell the package what each holder gets; it does the rest:

```php
use Packstub\Credits\Facades\Credits;
use Packstub\Credits\Support\Allowance;

// AppServiceProvider::boot()
Credits::allowancesUsing(fn (Team $team, string $wallet) => match ([$team->plan, $wallet]) {
    ['pro', 'default'] => [Allowance::monthly(10_000)->anchoredAt($team->subscribed_at)],
    ['team', 'default'] => [Allowance::monthly(50_000)->rollover(), Allowance::weekly(500)->named('bonus')],
    default => [],
});
```

The current period's lot is granted the first time the wallet is touched in it, keyed by the period, so it arrives exactly once however many workers race for it, and no scheduler has to run. It expires when the period ends, unless the allowance `rollover()`s.

| Builder | |
| --- | --- |
| `daily()`, `weekly()`, `monthly()`, `yearly()` | the period and the credits per period |
| `anchoredAt($moment)` | periods start on the moment's anniversaries (a subscription's start) instead of the calendar's; an anchor on the 31st renews on the last day of shorter months; nothing is granted before the anchor |
| `rollover()` | unused credits stay for good after the period |
| `rollover(2)` | unused credits stay for two more periods, then expire |
| `named('bonus')` | tells two allowances of one wallet apart; part of each period's key, so keep it stable. Two allowances of one wallet under the same name throw. |

Rules worth knowing:

- The resolver is asked on every operation; return what the plan grants **now**.
- **Upgrades apply at once.** When the allowance grows mid-period (a better plan, more seats), the next operation grants the difference, expiring with the period. When it shrinks, this period keeps what it already granted and the smaller amount applies from the next period. Downgrading and upgrading again within a period never grants the same credits twice.
- A wallet nobody touched for three months gets this period's allowance, not three.
- `period()` returns the current `[start, end)`, handy for "renews on" in the UI; `owed($wallet)` what this period has yet to grant the wallet.

```php
$allowance = Allowance::monthly(10_000)->anchoredAt($team->subscribed_at);
[, $renewsOn] = $allowance->period();
```
