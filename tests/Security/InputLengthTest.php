<?php

use Packstub\Credits\Exceptions\InvalidAmount;

/*
 * The string columns are varchar(255). A longer idempotency key or reason used to reach the database after the
 * lock and the writes, where a strict server rejected the line and a lenient one cut it short.
 */

it('refuses an idempotency key or a reason longer than the column before touching the wallet', function () {
    $wallet = $this->team()->credits();
    $wallet->grant(100);
    $long = str_repeat('k', 256);

    expect(fn () => $wallet->grant(10, key: $long))->toThrow(InvalidAmount::class)
        ->and(fn () => $wallet->spend(10, reason: $long))->toThrow(InvalidAmount::class)
        ->and(fn () => $wallet->hold(10, key: $long))->toThrow(InvalidAmount::class)
        ->and(fn () => $wallet->meter('sms', 1, key: $long))->toThrow(InvalidAmount::class)
        ->and(fn () => $wallet->adjust(-1, $long))->toThrow(InvalidAmount::class)
        ->and(fn () => $wallet->transfer($this->team('Other')->credits(), 1, key: $long))->toThrow(InvalidAmount::class)
        ->and(fn () => $wallet->grant(1, source: $long))->toThrow(InvalidAmount::class);

    $spend = $wallet->spend(5);
    expect(fn () => $wallet->refund($spend, reason: $long))->toThrow(InvalidAmount::class);

    expect($wallet->available())->toBe(95)
        ->and($wallet->transactions()->count())->toBe(2)
        ->and($wallet->grant(1, key: str_repeat('k', 255))->amount)->toBe(1);
});

it('refuses a wallet name that is empty or longer than the column', function () {
    $team = $this->team();

    expect(fn () => $team->credits(''))->toThrow(InvalidArgumentException::class)
        ->and(fn () => $team->credits(str_repeat('n', 256)))->toThrow(InvalidArgumentException::class)
        ->and($team->credits(str_repeat('n', 255))->name)->toHaveLength(255);
});

it('refuses a hold that would outlive any timestamp', function () {
    $wallet = $this->team()->credits();
    $wallet->grant(10);

    expect(fn () => $wallet->hold(5, ttl: PHP_INT_MAX))->toThrow(InvalidAmount::class)
        ->and($wallet->available())->toBe(10);
});
