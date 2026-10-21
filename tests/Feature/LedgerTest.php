<?php

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Packstub\Credits\Enums\TransactionType;
use Packstub\Credits\Events\BalanceLow;
use Packstub\Credits\Events\TransactionRecorded;
use Packstub\Credits\Exceptions\IdempotencyConflict;
use Packstub\Credits\Exceptions\InsufficientCredits;
use Packstub\Credits\Exceptions\InvalidAmount;
use Packstub\Credits\Models\Grant;

it('grants and spends', function () {
    $wallet = $this->team()->credits();

    $wallet->grant(100, 'Welcome bonus');
    $spend = $wallet->spend(30, 'Report export');

    expect($wallet->available())->toBe(70)
        ->and($spend->type)->toBe(TransactionType::Spend)
        ->and($spend->amount)->toBe(-30)
        ->and($spend->balance_after)->toBe(70);
});

it('refuses a spend larger than the balance and leaves the wallet as it was', function () {
    $wallet = $this->team()->credits();
    $wallet->grant(10);

    expect(fn () => $wallet->spend(11))->toThrow(function (InsufficientCredits $e) {
        expect($e->requested)->toBe(11)->and($e->available)->toBe(10)->and($e->missing())->toBe(1);
    });

    expect($wallet->available())->toBe(10)->and($wallet->transactions()->count())->toBe(1);
});

it('rejects zero and negative amounts', function () {
    $wallet = $this->team()->credits();

    expect(fn () => $wallet->grant(0))->toThrow(InvalidAmount::class)
        ->and(fn () => $wallet->spend(-5))->toThrow(InvalidAmount::class);
});

it('spends the lots that expire soonest first, then those that never expire', function () {
    $wallet = $this->team()->credits();

    $never = $wallet->grant(50, source: 'top-up');
    $late = $wallet->grant(20, expiresAt: now()->addDays(30), source: 'plan');
    $soon = $wallet->grant(10, expiresAt: now()->addDays(2), source: 'promo');

    $spend = $wallet->spend(25);

    expect($spend->allocations->pluck('amount', 'grant_id')->all())->toBe([
        $soon->allocations->first()->grant_id => -10,
        $late->allocations->first()->grant_id => -15,
    ])->and(Grant::query()->pluck('remaining', 'source')->all())->toBe(['top-up' => 50, 'plan' => 5, 'promo' => 0]);
});

it('expires lots on the next touch without a scheduler', function () {
    $wallet = $this->team()->credits();
    $wallet->grant(40, expiresAt: now()->addDay());
    $wallet->grant(10);

    $this->travel(2)->days();

    expect($wallet->available())->toBe(10);

    $expiry = $wallet->transactions()->latest('id')->first();

    expect($expiry->type)->toBe(TransactionType::Expire)
        ->and($expiry->amount)->toBe(-40)
        ->and($expiry->balance_after)->toBe(10);
});

it('replays a repeated idempotency key instead of charging twice', function () {
    $wallet = $this->team()->credits();
    $wallet->grant(100);

    $first = $wallet->spend(10, key: 'export:42');
    $again = $wallet->spend(10, key: 'export:42');

    expect($again->id)->toBe($first->id)->and($wallet->available())->toBe(90);
});

it('refuses a key reused for a different request', function () {
    $wallet = $this->team()->credits();
    $wallet->grant(100, key: 'stripe:pi_123');

    expect(fn () => $wallet->grant(500, key: 'stripe:pi_123'))->toThrow(IdempotencyConflict::class)
        ->and(fn () => $wallet->spend(100, key: 'stripe:pi_123'))->toThrow(IdempotencyConflict::class);
});

it('keeps separate wallets per holder by name', function () {
    $team = $this->team();

    $team->credits()->grant(100);
    $team->credits('sms')->grant(5);

    expect($team->credits()->available())->toBe(100)
        ->and($team->credits('sms')->available())->toBe(5)
        ->and($team->creditWallets()->count())->toBe(2)
        ->and($this->team('Globex')->credits()->available())->toBe(0);
});

it('lets an operator adjust either way, with a reason on the ledger', function () {
    $wallet = $this->team()->credits();
    $wallet->grant(10);

    $wallet->adjust(5, 'Goodwill after the outage');
    $wallet->adjust(-3, 'Duplicate top-up');

    expect($wallet->available())->toBe(12)
        ->and($wallet->transactions()->where('type', TransactionType::Adjust)->pluck('reason')->all())
        ->toBe(['Goodwill after the outage', 'Duplicate top-up']);
});

it('dispatches BalanceLow once when the balance crosses the threshold', function () {
    Event::fake([BalanceLow::class]);
    config(['packstub-credits.low_balance_threshold' => 20]);

    $wallet = $this->team()->credits();
    $wallet->grant(50);
    $wallet->spend(25);
    $wallet->spend(10);
    $wallet->spend(5);

    Event::assertDispatchedTimes(BalanceLow::class, 1);
    Event::assertDispatched(BalanceLow::class, fn (BalanceLow $e) => $e->available === 15 && $e->threshold === 20);
});

it('lets a wallet set its own threshold', function () {
    Event::fake([BalanceLow::class]);
    config(['packstub-credits.low_balance_threshold' => 20]);

    $wallet = $this->team()->credits();
    $wallet->forceFill(['low_balance_threshold' => 100])->save();
    $wallet->grant(150);
    $wallet->spend(60);

    Event::assertDispatched(BalanceLow::class, fn (BalanceLow $e) => $e->threshold === 100);
});

it('dispatches nothing when the surrounding transaction rolls back', function () {
    Event::fake([TransactionRecorded::class]);
    $wallet = $this->team()->credits();

    try {
        DB::transaction(function () use ($wallet) {
            $wallet->grant(100);

            throw new RuntimeException('Payment failed');
        });
    } catch (RuntimeException) {
    }

    Event::assertNotDispatched(TransactionRecorded::class);
    expect($wallet->fresh()->balance)->toBe(0);
});

it('dispatches nothing for an operation that failed', function () {
    Event::fake([TransactionRecorded::class]);
    $wallet = $this->team()->credits();

    expect(fn () => $wallet->spend(5))->toThrow(InsufficientCredits::class);

    Event::assertNotDispatched(TransactionRecorded::class);
});
