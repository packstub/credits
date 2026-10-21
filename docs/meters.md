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

A meter without a rate throws `UnknownMeter`. A keyed call below one credit leaves a zero line on the ledger, so a retry does not carry its fraction twice.
