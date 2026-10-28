# Meters

A meter prices usage in credits: `credits` per `per` units.

```php
// config/packstub-credits.php
'meters' => [
    'ai.input_tokens' => ['credits' => 3, 'per' => 1_000_000],
    'ai.cache_read_tokens' => ['credits' => 1, 'per' => 3_000_000],
    'ai.output_tokens' => ['credits' => 15, 'per' => 1_000_000],
    'sms' => ['credits' => 2],                // per defaults to 1
],
```

```php
$wallet->meter('sms', 3);
$wallet->meter(['ai.input_tokens' => 18_204, 'ai.output_tokens' => 911], 'Chat answer', key: "turn:{$id}");
$hold->settleUsage(['ai.input_tokens' => 18_204, 'ai.output_tokens' => 911]);
```

## Exact fractions

Credits are integers, usage rarely divides evenly. A meter charges whole credits and keeps the fraction on the wallet (`meter_remainders`, per meter). 400 tokens at 1 credit per 1,000 charge nothing and return `null`; the third call of that size charges 1. Many small calls cost exactly what one call of their total would: nothing is rounded away, nothing rounded up. Choose a small credit (1 credit = $0.00001) when you want prices to track a provider's closely.

## Usage that already happened

`meter()` charges after the fact, so a wallet that cannot cover it is charged what it has; the rest is recorded as `shortfall` in the transaction's meta and `Shortfall` fires. Gate expensive work with a [hold](holds.md) to avoid that.

## Rules

- A meter without a rate throws `UnknownMeter`, and so does a rate that is not a whole number of credits (zero or more) per a whole number of units (one or more).
- Quantities are integers or floats of zero or more; a float is rounded to the unit. A negative, missing or non-numeric quantity, or no usage at all, throws `InvalidAmount` and charges nothing.
- A keyed call below one credit leaves a zero line on the ledger, so a retry does not carry its fraction twice.

## Quotes

What usage would cost right now, the fractions the wallet carries included, without charging it; handy for sizing a hold or showing a price:

```php
$wallet->quote('sms', 120);                                              // 240
$wallet->quote(['ai.input_tokens' => 20_000, 'ai.output_tokens' => 4_000]);
```

## Rates per plan

The configured rates apply to everyone unless a resolver says otherwise. It is asked on every metered call and returns the rates that differ for this holder; meters it leaves out keep their configured rate:

```php
// AppServiceProvider::boot()
Credits::metersUsing(fn (Team $team, string $wallet) => match ($team->plan) {
    'enterprise' => ['ai.output_tokens' => ['credits' => 12, 'per' => 1_000_000]],
    default => [],
});
```

A rate change applies to the next call. Remainders are kept in units × credits, so change a meter's `per` only between periods if fractions in flight matter to you.
