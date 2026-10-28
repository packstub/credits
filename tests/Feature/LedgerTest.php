<?php

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Packstub\Credits\Enums\TransactionType;
use Packstub\Credits\Events\BalanceLow;
use Packstub\Credits\Events\TransactionRecorded;
use Packstub\Credits\Exceptions\CreditsException;
use Packstub\Credits\Exceptions\IdempotencyConflict;
use Packstub\Credits\Exceptions\InsufficientCredits;
use Packstub\Credits\Exceptions\InvalidAmount;
use Packstub\Credits\Models\Grant;
use Packstub\Credits\Models\Transaction;
use Packstub\Credits\Tests\Fixtures\Models\Team;

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

    expect($spend->allocations()->orderBy('id')->pluck('amount', 'grant_id')->all())->toBe([
        $soon->allocations->first()->grant_id => -10,
        $late->allocations->first()->grant_id => -15,
    ])->and(Grant::query()->orderBy('id')->pluck('remaining', 'source')->all())->toBe(['top-up' => 50, 'plan' => 5, 'promo' => 0]);
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
        ->and($wallet->transactions()->where('type', TransactionType::Adjust)->orderBy('id')->pluck('reason')->all())
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

it('reads the balance without writing when nothing is due', function () {
    $wallet = $this->team()->credits();
    $wallet->grant(100, expiresAt: now()->addDay());
    $wallet->hold(10);

    DB::enableQueryLog();
    $available = $wallet->available();
    $writes = collect(DB::getQueryLog())->pluck('query')->filter(fn (string $sql) => preg_match('/^(insert|update|delete)/i', $sql));

    expect($available)->toBe(90)->and($writes)->toBeEmpty();
});

it('picks up changes another process made when read', function () {
    $wallet = $this->team()->credits();
    $wallet->grant(100);

    Team::query()->first()->credits()->spend(40);

    expect($wallet->balance)->toBe(100)->and($wallet->available())->toBe(60);
});

it('says how much expires by a date', function () {
    $wallet = $this->team()->credits();
    $wallet->grant(10, expiresAt: now()->addDays(2));
    $wallet->grant(20, expiresAt: now()->addDays(20));
    $wallet->grant(50);
    $wallet->spend(5);

    expect($wallet->expiring(now()->addWeek()))->toBe(5)
        ->and($wallet->expiring(now()->addMonth()))->toBe(25);
});

it('tells the balance at any moment from the ledger alone', function () {
    $wallet = $this->team()->credits();
    $start = now()->toImmutable();

    $wallet->grant(100);
    $this->travel(1)->day();
    $wallet->spend(30);
    $this->travel(1)->day();
    $wallet->spend(20);

    expect($wallet->balanceAt($start->subMinute()))->toBe(0)
        ->and($wallet->balanceAt($start))->toBe(100)
        ->and($wallet->balanceAt($start->addDay()->addHour()))->toBe(70)
        ->and($wallet->balanceAt(now()))->toBe(50);
});

it('sums what usage cost over a period, refunds taken off', function () {
    $wallet = $this->team()->credits();
    $wallet->grant(1_000);
    $wallet->spend(10);
    $this->travel(1)->day();
    $from = now()->toImmutable();

    $spend = $wallet->spend(30);
    $wallet->hold(50)->settle(20);
    $wallet->meter(['ai.input_tokens' => 4_000, 'ai.output_tokens' => 1_000]);
    $wallet->meter('ai.input_tokens', 1_500);
    $wallet->refund($spend, 5);
    $wallet->adjust(-7, 'Not usage');

    expect($wallet->spent($from))->toBe(30 + 20 + 9 + 1 - 5)
        ->and($wallet->spent())->toBe(10 + 55)
        ->and($wallet->spent(to: $from))->toBe(10)
        ->and($wallet->usage($from))->toBe(['ai.input_tokens' => 5_500, 'ai.output_tokens' => 1_000]);
});

it('adds an adjustment that expires when told to', function () {
    $wallet = $this->team()->credits();

    $wallet->adjust(50, 'Make-good for the outage', expiresAt: now()->addDays(30));

    expect(Grant::query()->sole()->only('source', 'amount'))->toBe(['source' => 'adjustment', 'amount' => 50])
        ->and(Grant::query()->sole()->expires_at->toDateString())->toBe(now()->addDays(30)->toDateString());
});

it('keeps the ledger append-only', function () {
    $wallet = $this->team()->credits();
    $transaction = $wallet->grant(10);

    expect(fn () => $transaction->update(['amount' => 1_000]))->toThrow(LogicException::class, 'append-only')
        ->and(fn () => $transaction->delete())->toThrow(LogicException::class, 'append-only')
        ->and(fn () => $transaction->allocations->first()->update(['amount' => 1_000]))->toThrow(LogicException::class, 'append-only')
        ->and(Transaction::query()->sole()->amount)->toBe(10);
});

it('refuses a wallet for a model that is not saved yet', function () {
    expect(fn () => (new Team(['name' => 'Draft']))->credits())->toThrow(LogicException::class, 'Save the Team');
});

it('throws exceptions an app can catch as one', function () {
    $wallet = $this->team()->credits();

    // toThrow() takes an interface's name for a message, so catch by hand.
    $caught = function (Closure $operation) {
        try {
            $operation();
        } catch (CreditsException) {
            return true;
        }

        return false;
    };

    expect($caught(fn () => $wallet->spend(1)))->toBeTrue()
        ->and($caught(fn () => $wallet->grant(-1)))->toBeTrue()
        ->and($caught(fn () => $wallet->meter('nope', 1)))->toBeTrue();
});

it('filters the statement by type and period', function () {
    $wallet = $this->team()->credits();
    $wallet->grant(100);
    $this->travel(1)->day();
    $wallet->spend(10);
    $wallet->spend(5);

    expect($wallet->transactions()->ofType(TransactionType::Spend)->count())->toBe(2)
        ->and($wallet->transactions()->between(now()->startOfDay())->count())->toBe(2)
        ->and($wallet->transactions()->between(null, now()->startOfDay())->count())->toBe(1);
});
