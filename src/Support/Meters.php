<?php

namespace Packstub\Credits\Support;

use Packstub\Credits\Exceptions\InvalidAmount;
use Packstub\Credits\Exceptions\UnknownMeter;
use Packstub\Credits\Facades\Credits;
use Packstub\Credits\Models\Wallet;

/**
 * Usage priced in credits. A meter's rate is `credits` per `per` units; the
 * wallet keeps each meter's remainder in units × credits, so the sum of
 * many small calls is charged exactly what one call of their total would
 * be. Integers throughout: a float quantity is rounded to the unit first.
 */
final class Meters
{
    /**
     * @param  array<string, int|float>  $usage  meter => quantity
     * @return array{0: int, 1: array<string, int>} credits to charge, the wallet's new remainders
     */
    public static function price(Wallet $wallet, array $usage): array
    {
        if ($usage === []) {
            throw InvalidAmount::noUsage();
        }

        $rates = Credits::metersFor($wallet);
        $remainders = $wallet->meter_remainders ?? [];
        $credits = 0;

        foreach ($usage as $meter => $quantity) {
            // A negative quantity would carry a negative fraction and hand out credits no lot backs.
            if ((! is_int($quantity) && ! is_float($quantity)) || is_nan((float) $quantity) || $quantity < 0) {
                throw InvalidAmount::usage((string) $meter, $quantity);
            }

            // Past the integer range a float wraps when cast (PHP 8.4 says nothing), so it would arrive negative too.
            if (is_float($quantity) && $quantity >= (float) PHP_INT_MAX) {
                throw InvalidAmount::usageTooLarge((string) $meter, $quantity);
            }

            [$rate, $per] = self::rate((string) $meter, $rates);
            // round() returns a float even for an int, and a float near the limit no longer casts back exactly.
            $priced = (is_int($quantity) ? $quantity : (int) round($quantity)) * $rate;
            $carried = ($remainders[$meter] ?? 0) + $priced;

            // An integer product or sum that overflows becomes a float, which the remainder could never hold.
            if (! is_int($priced) || ! is_int($carried)) {
                throw InvalidAmount::usageTooLarge((string) $meter, $quantity);
            }

            $credits += intdiv($carried, $per);
            $remainders[$meter] = $carried % $per;
        }

        return [$credits, array_filter($remainders)];
    }

    /**
     * @param  array<string, mixed>|null  $rates  meter => ['credits' => …, 'per' => …]; the configured meters by default
     * @return array{0: int, 1: int}
     */
    public static function rate(string $meter, ?array $rates = null): array
    {
        // Meter names hold dots ("ai.input_tokens"), so the array is read whole, not through a dotted config key.
        $config = ($rates ?? config('packstub-credits.meters', []))[$meter] ?? null;

        if (! is_array($config) || ! isset($config['credits'])) {
            throw new UnknownMeter($meter);
        }

        $credits = $config['credits'];
        $per = $config['per'] ?? 1;

        if (! is_int($credits) || ! is_int($per) || $credits < 0 || $per < 1) {
            throw UnknownMeter::misconfigured($meter);
        }

        return [$credits, $per];
    }
}
