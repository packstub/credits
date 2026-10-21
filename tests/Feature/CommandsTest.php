<?php

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Packstub\Credits\Events\TransactionRecorded;
use Packstub\Credits\Models\Wallet;

/** A busy month: every operation the ledger has, across lots that expire and lots that don't. */
function busyWallet(Wallet $wallet): Wallet
{
    $wallet->grant(500, source: 'top-up');
    $wallet->grant(200, expiresAt: now()->addDays(10), source: 'plan');
    $wallet->grant(30, expiresAt: now()->addDay(), source: 'promo');

    $spend = $wallet->spend(120);
    $wallet->hold(80)->settle(55);
    $wallet->hold(40)->release();
    $wallet->hold(300)->settle(320);
    $wallet->meter(['ai.input_tokens' => 12_345, 'ai.output_tokens' => 2_222]);
    $wallet->refund($spend, 70);
    $wallet->adjust(-15, 'Correction');
    $wallet->hold(25, ttl: 60);

    test()->travel(2)->days();
    $wallet->spend(10);

    return $wallet;
}

it('proves busy wallets balance', function () {
    busyWallet($this->team('Acme')->credits());
    busyWallet($this->team('Globex')->credits('sms'));

    $this->artisan('credits:audit')
        ->expectsOutputToContain('2 wallets balanced.')
        ->assertSuccessful();
});

it('finds a wallet someone edited by hand', function () {
    $wallet = busyWallet($this->team()->credits());

    DB::table('credit_wallets')->where('id', $wallet->id)->increment('balance', 100);

    $this->artisan('credits:audit')
        ->expectsOutputToContain('problems found.')
        ->assertFailed();
});

it('finds a lot whose remaining disagrees with its allocations', function () {
    $wallet = busyWallet($this->team()->credits());

    DB::table('credit_grants')->where('wallet_id', $wallet->id)->where('source', 'top-up')->decrement('remaining', 1);
    DB::table('credit_wallets')->where('id', $wallet->id)->decrement('balance', 1);

    $this->artisan('credits:audit', ['--wallet' => [$wallet->id]])
        ->expectsOutputToContain('remaining = its allocations')
        ->assertFailed();
});

it('expires lots and releases holds of wallets nobody touches', function () {
    $idle = $this->team('Idle')->credits();
    $idle->grant(40, expiresAt: now()->addHour());
    $idle->grant(10);
    $idle->hold(5, ttl: 60);

    $this->team('Fresh')->credits()->grant(10);

    $this->travel(2)->hours();
    Event::fake([TransactionRecorded::class]);

    $this->artisan('credits:expire')->expectsOutputToContain('1 wallet caught up.')->assertSuccessful();

    Event::assertDispatchedTimes(TransactionRecorded::class, 2);
    expect(Wallet::query()->find($idle->id)->only('balance', 'held'))->toBe(['balance' => 10, 'held' => 0]);
});
