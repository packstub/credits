<?php

use Packstub\Credits\Exceptions\InvalidAmount;
use Packstub\Credits\Models\Hold;

/*
 * Numbers too large for a 64-bit integer. PHP wraps or turns them into floats without a word (on PHP 8.4; 8.5
 * warns), so a quantity or an amount past the limit used to come out negative or as a float on its way into
 * the ledger, and a negative charge is a grant no lot backs. Every one of these must be refused up front and
 * leave the wallet untouched.
 */

it('refuses metered usage too large to price instead of minting credits', function () {
    $wallet = $this->team()->credits();
    $wallet->grant(10);

    // Floats past the integer range, an integer whose price overflows, and a fraction that rounds past the range.
    $quantities = [
        ['ai.input_tokens' => 1e19],
        ['ai.input_tokens' => 1e30],
        ['ai.input_tokens' => (float) PHP_INT_MAX],
        ['ai.output_tokens' => PHP_INT_MAX],
        ['ai.output_tokens' => PHP_INT_MAX / 4],
        ['sms' => INF],
    ];

    foreach ($quantities as $usage) {
        expect(fn () => $wallet->meter($usage))->toThrow(InvalidAmount::class);
        expect(fn () => $wallet->quote($usage))->toThrow(InvalidAmount::class);

        $hold = $wallet->hold(5);
        expect(fn () => $hold->settleUsage($usage))->toThrow(InvalidAmount::class);
        expect($hold->fresh()->isPending())->toBeTrue();
        $hold->release();
    }

    expect($wallet->available())->toBe(10)
        ->and($wallet->meter_remainders)->toBeEmpty()
        ->and($wallet->transactions()->where('amount', '>', 0)->count())->toBe(1)
        ->and(Hold::query()->pending()->count())->toBe(0);

    $this->artisan('credits:audit')->assertSuccessful();
});

it('refuses a remainder that would overflow when carried', function () {
    $wallet = $this->team()->credits();
    $wallet->grant(10);
    $wallet->meter('ai.input_tokens', 999);

    expect(fn () => $wallet->meter('ai.input_tokens', PHP_INT_MAX - 500))->toThrow(InvalidAmount::class)
        ->and($wallet->fresh()->meter_remainders)->toBe(['ai.input_tokens' => 999]);

    $this->artisan('credits:audit')->assertSuccessful();
});

it('refuses a grant, a refund or a transfer that would overflow the balance', function () {
    $full = $this->team('Full')->credits();
    $other = $this->team('Other')->credits();

    $full->grant(PHP_INT_MAX - 10);
    $other->grant(100);

    expect(fn () => $full->grant(11))->toThrow(InvalidAmount::class)
        ->and(fn () => $full->adjust(11, 'too much'))->toThrow(InvalidAmount::class)
        ->and(fn () => $other->transfer($full, 11))->toThrow(InvalidAmount::class);

    // Fill the wallet to the limit, then try to put the spent credits back on top.
    $spend = $full->spend(5);
    $full->grant(15);

    expect(fn () => $full->refund($spend))->toThrow(InvalidAmount::class);

    expect($full->total())->toBe(PHP_INT_MAX)
        ->and($other->total())->toBe(100)
        ->and($other->transactions()->count())->toBe(1);

    $this->artisan('credits:audit')->assertSuccessful();
});

it('refuses the one negative amount that cannot be negated', function () {
    $wallet = $this->team()->credits();

    expect(fn () => $wallet->adjust(PHP_INT_MIN, 'oops'))->toThrow(InvalidAmount::class)
        ->and($wallet->transactions()->count())->toBe(0);
});
