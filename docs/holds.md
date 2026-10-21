# Holds and AI calls

The cost of an AI call is known only after it ran. A hold reserves an estimate before the call, so two parallel calls cannot spend the same credits, and is settled with the real cost afterwards.

```php
$hold = $team->credits()->hold(200, 'AI turn', ttl: 600, key: "turn:{$turn->id}");

// … the call …

$hold->settle(137);      // charge 137, give the other 63 back
$hold->release();        // or: it never ran, charge nothing
```

While pending, the hold's credits count in `total()` but not in `available()`.

## Settle, capture, release

| Method | Charges | Over the hold |
| --- | --- | --- |
| `settle($amount)` | what the work cost | charged from what is available; what the wallet cannot cover is recorded as `shortfall` on the hold and the capture's meta, and `Shortfall` fires. Never throws for lack of credits: the work already happened. |
| `capture($amount)` | exactly `$amount` | allowed if the wallet has the difference, otherwise `InsufficientCredits` and the hold stays pending |
| `settleUsage([...])` | the usage priced by the [meters](meters.md) | like `settle` |
| `release()` | nothing | |

A hold is settled, captured or released once; again throws `HoldNotPending`.

## Holds that outlive their call

A hold nobody settles before `expires_at` (a worker that died mid-call) is released on the wallet's next use, with status `expired`, and its credits come back. If the work did finish, only late, `settle()` still charges it, from what is available at that moment, marked `late` in the capture's meta. `capture()` and `release()` on an expired hold throw.

Credits a hold reserved do not expire under it: if their lot runs out while the hold is pending, those credits stay until the hold is settled, and what the settle gives back expires on the next use.

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

    Hold::query()->where('meta->invocation', $event->invocationId)->where('status', 'pending')->first()
        ?->settleUsage([
            'ai.input_tokens' => $usage->uncachedInputTokens(),
            'ai.cache_read_tokens' => $usage->cacheReadInputTokens ?? 0,
            'ai.output_tokens' => $usage->outputTokens,
        ]);
});

Event::listen(AgentFailed::class, function (AgentFailed $event) {
    Hold::query()->where('meta->invocation', $event->invocationId)->where('status', 'pending')->first()?->release();
});
```

`inputTokens` includes cached tokens; `uncachedInputTokens()` leaves them out, so cache reads can have their own, cheaper meter. A streamed response carries the same `usage` once the stream ends.

To refuse a call the customer cannot afford, check before prompting: `$team->credits()->canSpend(200)`, or let `hold()` throw `InsufficientCredits` from the `PromptingAgent` listener.
