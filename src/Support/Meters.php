<?php

namespace Packstub\Credits\Support;

use Packstub\Credits\Exceptions\UnknownMeter;
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
        $remainders = $wallet->meter_remainders ?? [];
        $credits = 0;

        foreach ($usage as $meter => $quantity) {
            [$rate, $per] = self::rate($meter);
            $carried = ($remainders[$meter] ?? 0) + (int) round($quantity) * $rate;

            $credits += intdiv($carried, $per);
            $remainders[$meter] = $carried % $per;
        }

        return [$credits, array_filter($remainders)];
    }

    /** @return array{0: int, 1: int} */
    public static function rate(string $meter): array
    {
        // Meter names hold dots ("ai.input_tokens"), so the array is read whole, not through a dotted config key.
        $config = config('packstub-credits.meters', [])[$meter] ?? null;

        if (! is_array($config) || ! isset($config['credits'])) {
            throw new UnknownMeter($meter);
        }

        return [(int) $config['credits'], max(1, (int) ($config['per'] ?? 1))];
    }
}
