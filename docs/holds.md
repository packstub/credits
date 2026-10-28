# Holds and AI calls

The cost of an AI call is known only after it ran. A hold reserves an estimate before the call, so two parallel calls cannot spend the same credits, and is settled with the real cost afterwards.

```php
$hold = $team->credits()->hold(200, 'AI turn', ttl: 600, key: "turn:{$turn->id}");

// … the call …

$hold->settle(137);      // charge 137, give the other 63 back
$hold->release();        // or: it never ran, charge nothing
```

While pending, the hold's credits count in `total()` but not in `available()`. A hold lives `ttl` seconds (the `hold_ttl` config, 900 by default); a zero or negative `ttl` throws `InvalidAmount`.

## Settle, capture, release

| Method | Charges | Over the hold |
| --- | --- | --- |
| `settle($amount)` | what the work cost | charged from what is available; what the wallet cannot cover is recorded as `shortfall` on the hold and the capture's meta, and `Shortfall` fires. Never throws for lack of credits: the work already happened. |
| `capture($amount)` | exactly `$amount` | allowed if the wallet has the difference, otherwise `InsufficientCredits` and the hold stays pending |
| `settleUsage([...])` | the usage priced by the [meters](meters.md) | like `settle` |
| `release()` | nothing | |

A hold is settled, captured or released once; again throws `HoldNotPending`. Settling, capturing, releasing and extending check nothing about who asks: when the hold's id comes from a request, look it up through the wallet the user may act on (`$team->credits()->holds()->pending()->findOrFail($id)`), never with `Hold::findOrFail()`, or any user can settle any hold. The same goes for a `Transaction` handed to `refund()`, which the wallet refuses when the line is not its own. Guard a retried job with `$hold->isPending()`, or look at what happened with `$hold->isSettled()` and `$hold->settlement` (the capture line). `$hold->transactions` lists all its lines, and `$wallet->holds()->pending()` the holds still open.

## Long-running work

A hold placed for a long stream or a slow job can be kept alive while the work runs:

```php
$hold->extend();        // another hold_ttl seconds from now
$hold->extend(600);     // another 10 minutes from now
```

`extend()` never shortens a hold, and throws `HoldNotPending` once the hold is settled, released or expired.

## Holds that outlive their call

A hold nobody settles before `expires_at` (a worker that died mid-call) is released on the wallet's next use, with status `expired`, and its credits come back. If the work did finish, only late, `settle()` still charges it, from what is available at that moment, marked `late` in the capture's meta. `capture()` and `release()` on an expired hold throw.

## Holds and expiring credits

A hold reserves the wallet's soonest-expiring credits: the ones the work would have been charged from had it been charged up front. Those credits do not expire under the hold. If their lot runs out while the hold is pending, they stay until the hold is settled or released, the settle is charged from them, and whatever it gives back expires at once.

So a turn that starts at 23:55 on the last day of the month is paid for by that month's allowance, not the next one's, and credits bought while the hold was pending stay untouched. Spends, meters and transfers never take the credits pending holds reserve; a capture takes them first.

## With laravel/ai

laravel/ai dispatches `PromptingAgent` before a prompt, then `AgentPrompted` with the response or `AgentFailed`, all carrying the same `invocationId`. Hold on the first, settle or release on the others:

```php
use Laravel\Ai\Events\AgentFailed;
use Laravel\Ai\Events\AgentPrompted;
use Laravel\Ai\Events\PromptingAgent;
use Packstub\Credits\Models\Hold;

Event::listen(PromptingAgent::class, function (PromptingAgent $event) {
    $team = Filament::getTenant() ?? auth()->user()->currentTeam;

    $team->credits()->hold(200, 'AI turn', key: "ai:{$event->invocationId}", meta: ['invocation' => $event->invocationId]);
});

Event::listen(AgentPrompted::class, function (AgentPrompted $event) {
    $usage = $event->response->usage;

    Hold::query()->where('meta->invocation', $event->invocationId)->pending()->first()
        ?->settleUsage([
            'ai.input_tokens' => $usage->uncachedInputTokens(),
            'ai.cache_read_tokens' => $usage->cacheReadInputTokens ?? 0,
            'ai.output_tokens' => $usage->outputTokens,
        ]);
});

Event::listen(AgentFailed::class, function (AgentFailed $event) {
    Hold::query()->where('meta->invocation', $event->invocationId)->pending()->first()?->release();
});
```

`inputTokens` includes cached tokens; `uncachedInputTokens()` leaves them out, so cache reads can have their own, cheaper meter. A streamed response carries the same `usage` once the stream ends.

To refuse a call the customer cannot afford, check before prompting: `$team->credits()->canSpend(200)`, or let `hold()` throw `InsufficientCredits` from the `PromptingAgent` listener.
