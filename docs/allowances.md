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
| `anchoredAt($moment)` | periods start on the moment's anniversaries (a subscription's start) instead of the calendar's; an anchor on the 31st renews on the last day of shorter months |
| `rollover()` | unused credits stay after the period |
| `named('bonus')` | tells two allowances of one wallet apart; part of each period's key, so keep it stable |

Rules worth knowing:

- The resolver is asked on every operation; return what the plan grants **now**. A plan change takes effect from the next period (the current one was already granted under its key).
- A wallet nobody touched for three months gets this period's allowance, not three.
- `period()` returns the current `[start, end)`, handy for "renews on" in the UI.
