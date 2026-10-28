<?php

use Packstub\Credits\Exceptions\HoldNotPending;
use Packstub\Credits\Exceptions\InsufficientCredits;
use Packstub\Credits\Facades\Credits;
use Packstub\Credits\Models\Hold;
use Packstub\Credits\Models\Transaction;
use Packstub\Credits\Models\Wallet;
use Packstub\Credits\Support\Allowance;
use Packstub\Credits\Tests\Fixtures\Models\Team;

/**
 * Hundreds of operations in a random order, over three wallets, through
 * expiring lots, allowances, holds that lapse and periods that end. Whatever
 * the order, the books must balance after every step; each seed is a
 * reproducible history.
 */
it('keeps the books balanced through any sequence of operations', function (int $seed) {
    mt_srand($seed);

    Credits::allowancesUsing(fn (Team $team) => $team->plan === 'pro' ? [Allowance::weekly(300), Allowance::daily(20)->named('daily')->rollover(1)] : []);

    $wallets = [
        $this->team('Pro')->credits(),
        tap($this->team('Free'))->update(['plan' => 'free'])->credits(),
        $this->team('Sms')->credits('sms'),
    ];

    $holds = [];
    $charges = [];

    foreach (range(1, 250) as $step) {
        $wallet = $wallets[mt_rand(0, 2)];

        try {
            match (mt_rand(0, 11)) {
                0, 1 => $wallet->grant(mt_rand(1, 200), expiresAt: mt_rand(0, 1) ? now()->addMinutes(mt_rand(1, 3_000)) : null),
                2, 3 => $charges[] = $wallet->spend(mt_rand(1, 80)),
                4, 5 => $holds[] = $wallet->hold(mt_rand(1, 120), ttl: mt_rand(60, 4_000)),
                6 => $holds ? ($charges[] = $holds[array_rand($holds)]->settle(mt_rand(0, 150))) : null,
                7 => $holds ? $holds[array_rand($holds)]->release() : null,
                8 => ($charge = $wallet->meter(['ai.input_tokens' => mt_rand(0, 9_000), 'ai.output_tokens' => mt_rand(0, 3_000)])) ? $charges[] = $charge : null,
                9 => $charges ? (fn (Transaction $t) => $t->refundable() > 0 ? $t->wallet->refund($t, mt_rand(1, $t->refundable())) : null)($charges[array_rand($charges)]) : null,
                10 => $wallet->transfer($wallets[($wallets[0]->is($wallet) ? 1 : 0)], mt_rand(1, 50)),
                11 => $wallet->adjust(mt_rand(-30, 30) ?: 1, 'Random correction'),
            };
        } catch (InsufficientCredits|HoldNotPending) {
            // Refusals are part of the history: they must leave nothing behind.
        }

        $this->travel(mt_rand(0, 180))->minutes();

        if ($step % 25 === 0) {
            $this->artisan('credits:expire');
            $this->artisan('credits:audit')->assertSuccessful();
        }
    }

    foreach ($wallets as $wallet) {
        $wallet->available();

        expect($wallet->held)->toBe((int) Hold::query()->where('wallet_id', $wallet->id)->pending()->sum('amount'))
            ->and($wallet->balance)->toBeGreaterThanOrEqual($wallet->held)
            ->and($wallet->balance)->toBe((int) $wallet->grants()->sum('remaining'));
    }

    $this->artisan('credits:audit')->expectsOutputToContain('3 wallets balanced.')->assertSuccessful();
})->with([1, 7, 42, 1_234, 9_001, 31_337]);

it('loses no credit to expiry that a hold had reserved, in any order', function (int $seed) {
    mt_srand($seed);

    $wallet = $this->team()->credits();

    foreach (range(1, 40) as $i) {
        $amount = mt_rand(10, 100);
        $wallet->grant($amount, expiresAt: now()->addMinutes(mt_rand(5, 60)));

        // A fresh, never-expiring top-up beside it: it must not pay for what the expiring lot reserved.
        $wallet->grant(mt_rand(1, 50));
        $hold = $wallet->hold(mt_rand(1, $amount), ttl: 7_200);

        $this->travel(mt_rand(61, 115))->minutes();
        $hold->settle(mt_rand(0, $hold->amount));

        expect($wallet->available())->toBe(Wallet::query()->find($wallet->id)->balance);
    }

    // Every top-up survived: each hold was charged from the expiring lot it reserved.
    expect((int) $wallet->grants()->whereNull('expires_at')->sum('remaining'))->toBe((int) $wallet->grants()->whereNull('expires_at')->sum('amount'));
    $this->artisan('credits:audit')->assertSuccessful();
})->with([3, 17, 256]);
