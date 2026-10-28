<?php

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Packstub\Credits\Events\TransactionRecorded;
use Packstub\Credits\Facades\Credits;
use Packstub\Credits\Models\Wallet;
use Packstub\Credits\Tests\Fixtures\Models\Team;

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

it('finds a lot holding more than it was granted', function () {
    $wallet = $this->team()->credits();
    $grant = $wallet->grant(10);
    $lot = $grant->allocations->first()->grant_id;

    // Every cached number agrees, yet the lot holds more than it was ever given.
    DB::table('credit_grants')->where('id', $lot)->update(['amount' => 5]);

    $this->artisan('credits:audit')
        ->expectsOutputToContain('0 <= remaining <= amount')
        ->assertFailed();
});

it('proves transfers balance on both wallets', function () {
    $pool = busyWallet($this->team('Acme')->credits());
    $member = $this->team('Member')->credits();

    $pool->transfer($member, $pool->available());
    $member->spend(1);

    $this->artisan('credits:audit')->expectsOutputToContain('2 wallets balanced.')->assertSuccessful();
});

it('counts only the wallets it changed, not those whose expired lot a hold still needs', function () {
    $wallet = $this->team()->credits();
    $wallet->grant(40, expiresAt: now()->addHour());
    $wallet->hold(40, ttl: 7200);

    $this->travel(90)->minutes();

    $this->artisan('credits:expire')->expectsOutputToContain('0 wallets caught up.')->assertSuccessful();
    expect($wallet->total())->toBe(40);
});

it('keeps catching up the other wallets when one fails', function () {
    $broken = $this->team('Broken')->credits();
    $broken->grant(10, expiresAt: now()->addHour());
    $fine = $this->team('Fine')->credits();
    $fine->grant(10, expiresAt: now()->addHour());

    Credits::allowancesUsing(fn (Team $team) => $team->name === 'Broken' ? throw new RuntimeException('Billing API down') : []);
    $this->travel(2)->hours();

    $this->artisan('credits:expire')->expectsOutputToContain('1 wallet caught up.')->assertSuccessful();
    expect(Wallet::query()->find($fine->id)->balance)->toBe(0)
        ->and(Wallet::query()->find($broken->id)->balance)->toBe(10);
});
