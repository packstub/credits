# Selling credits

Credits for Laravel keeps the books; your payment provider takes the money. Grant the credits from the provider's webhook with the payment's id as the [idempotency key](spending.md#idempotency-keys), so a webhook delivered twice grants once.

## Laravel Cashier (Stripe)

Sell a pack as a one-off Stripe Checkout, the number of credits in the session's metadata:

```php
Route::post('/billing/credits', function (Request $request) {
    return $request->user()->checkout(['price_credits_1000' => 1], [
        'success_url' => route('billing'),
        'cancel_url' => route('billing'),
        'metadata' => ['credits' => 1_000],
    ]);
});
```

Grant them when Stripe says the session is paid (enable the `checkout.session.completed` event on your webhook endpoint):

```php
use Laravel\Cashier\Cashier;
use Laravel\Cashier\Events\WebhookReceived;

Event::listen(WebhookReceived::class, function (WebhookReceived $event) {
    if ($event->payload['type'] !== 'checkout.session.completed') {
        return;
    }

    $session = $event->payload['data']['object'];

    if ($session['payment_status'] !== 'paid' || ! isset($session['metadata']['credits'])) {
        return;
    }

    Cashier::findBillable($session['customer'])?->credits()->grant(
        (int) $session['metadata']['credits'],
        'Credit pack',
        source: 'top-up',
        key: "stripe:{$session['id']}",
        meta: ['stripe_session' => $session['id']],
    );
});
```

## Laravel Cashier (Paddle)

```php
return view('billing', [
    'checkout' => $request->user()->checkout('pri_credits_1000')->customData(['credits' => 1_000]),
]);
```

```php
use Laravel\Paddle\Events\TransactionCompleted;

Event::listen(TransactionCompleted::class, function (TransactionCompleted $event) {
    $credits = $event->payload['data']['custom_data']['credits'] ?? null;

    if ($credits !== null) {
        $event->billable->credits()->grant((int) $credits, 'Credit pack', source: 'top-up', key: "paddle:{$event->payload['data']['id']}");
    }
});
```

## Credits that come with a plan

A subscription's monthly credits are an [allowance](allowances.md), not a webhook grant: tell the package what each plan gives and it grants every period on first use, anchored to the subscription's start if you like, upgrades included.

```php
Credits::allowancesUsing(fn (User $user, string $wallet) => match (true) {
    $user->subscribedToPrice('price_pro') => [Allowance::monthly(10_000)->anchoredAt($user->subscription()->created_at)],
    default => [],
});
```

## Refunded payments

When a payment is refunded, decide what happens to credits that may already be spent. Taking back what is left is one operator adjustment:

```php
$wallet = $user->credits();

if ($left = min($credits, $wallet->available())) {
    $wallet->adjust(-$left, "Refund of payment {$paymentId}", key: "refund:{$paymentId}");
}
```

## Expiring purchases

Purchased credits often expire after a year. Pass the expiry with the grant; spends take the soonest-expiring credits first, so a customer's plan credits and older purchases are used before newer ones:

```php
$wallet->grant(1_000, 'Credit pack', expiresAt: now()->addYear(), source: 'top-up', key: "stripe:{$session['id']}");
```
