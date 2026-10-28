<?php

use Illuminate\Support\Facades\Event;
use Packstub\Credits\Enums\HoldStatus;
use Packstub\Credits\Enums\TransactionType;
use Packstub\Credits\Events\Shortfall;
use Packstub\Credits\Exceptions\HoldNotPending;
use Packstub\Credits\Exceptions\InsufficientCredits;
use Packstub\Credits\Exceptions\InvalidAmount;
use Packstub\Credits\Facades\Credits;
use Packstub\Credits\Models\Hold;
use Packstub\Credits\Support\Allowance;

it('reserves credits so two calls cannot spend the same ones', function () {
    $wallet = $this->team()->credits();
    $wallet->grant(100);

    $hold = $wallet->hold(80, 'AI turn');

    expect($wallet->available())->toBe(20)
        ->and($wallet->total())->toBe(100)
        ->and(fn () => $wallet->hold(30))->toThrow(InsufficientCredits::class)
        ->and(fn () => $wallet->spend(21))->toThrow(InsufficientCredits::class)
        ->and($hold->isPending())->toBeTrue();
});

it('settles for less than was held and gives the rest back', function () {
    $wallet = $this->team()->credits();
    $wallet->grant(100);

    $capture = $wallet->hold(80)->settle(32);

    expect($wallet->available())->toBe(68)
        ->and($wallet->held)->toBe(0)
        ->and($capture->amount)->toBe(-32)
        ->and($capture->held_change)->toBe(-80)
        ->and($capture->hold->status)->toBe(HoldStatus::Captured)
        ->and($capture->hold->captured)->toBe(32);
});

it('captures more than was held when the wallet has the difference', function () {
    $wallet = $this->team()->credits();
    $wallet->grant(100);

    $wallet->hold(50)->capture(70);

    expect($wallet->available())->toBe(30);
});

it('refuses a strict capture the wallet cannot cover and keeps the hold pending', function () {
    $wallet = $this->team()->credits();
    $wallet->grant(100);
    $hold = $wallet->hold(50);
    $wallet->hold(40);

    expect(fn () => $hold->capture(70))->toThrow(InsufficientCredits::class)
        ->and($hold->fresh()->isPending())->toBeTrue()
        ->and($wallet->available())->toBe(10);
});

it('settles work that cost more than the wallet holds, recording the shortfall', function () {
    Event::fake([Shortfall::class]);

    $wallet = $this->team()->credits();
    $wallet->grant(60);
    $hold = $wallet->hold(50);

    $capture = $hold->settle(75);

    expect($capture->amount)->toBe(-60)
        ->and($capture->meta['shortfall'])->toBe(15)
        ->and($hold->shortfall)->toBe(15)
        ->and($wallet->available())->toBe(0);

    Event::assertDispatched(Shortfall::class, fn (Shortfall $e) => $e->amount === 15 && $e->hold->is($hold));
});

it('releases a hold without charging', function () {
    $wallet = $this->team()->credits();
    $wallet->grant(100);
    $hold = $wallet->hold(70);

    $hold->release();

    expect($wallet->available())->toBe(100)
        ->and($hold->status)->toBe(HoldStatus::Released)
        ->and(fn () => $hold->settle(10))->toThrow(HoldNotPending::class)
        ->and(fn () => $hold->release())->toThrow(HoldNotPending::class);
});

it('releases a hold nobody settled when it expires', function () {
    $wallet = $this->team()->credits();
    $wallet->grant(100);
    $hold = $wallet->hold(70, ttl: 60);

    $this->travel(2)->minutes();

    expect($wallet->available())->toBe(100)
        ->and($hold->fresh()->status)->toBe(HoldStatus::Expired);
});

it('still settles work that ran past its hold, from what is available', function () {
    $wallet = $this->team()->credits();
    $wallet->grant(100);
    $hold = $wallet->hold(70, ttl: 60);

    $this->travel(5)->minutes();

    $capture = $hold->settle(40);

    expect($capture->amount)->toBe(-40)
        ->and($capture->held_change)->toBe(0)
        ->and($capture->meta['late'])->toBeTrue()
        ->and($wallet->available())->toBe(60)
        ->and(fn () => $hold->capture(10))->toThrow(HoldNotPending::class);
});

it('keeps held credits alive past their lot\'s expiry until the hold is settled', function () {
    $wallet = $this->team()->credits();
    $wallet->grant(100, expiresAt: now()->addHour());
    $hold = $wallet->hold(80, ttl: 7200);

    $this->travel(90)->minutes();

    // 20 unreserved credits expired; the 80 the hold reserves stay for it.
    expect($wallet->total())->toBe(80)->and($wallet->available())->toBe(0);

    $hold->settle(50);

    // The 30 the settle gave back belonged to an expired lot, so they expire on the next touch.
    expect($wallet->available())->toBe(0)->and($wallet->total())->toBe(0);
});

it('replays a hold placed twice with the same key', function () {
    $wallet = $this->team()->credits();
    $wallet->grant(100);

    $first = $wallet->hold(30, key: 'turn:9f2c');
    $again = $wallet->hold(30, key: 'turn:9f2c');

    expect($again->id)->toBe($first->id)->and($wallet->available())->toBe(70);
});

it('settles a hold with measured usage priced by the meters', function () {
    $wallet = $this->team()->credits();
    $wallet->grant(100);

    // 2,500 input tokens at 1 per 1,000 and 1,200 output tokens at 5 per 1,000: 2.5 + 6 = 8.5 credits.
    $capture = $wallet->hold(20)->settleUsage(['ai.input_tokens' => 2_500, 'ai.output_tokens' => 1_200]);

    expect($capture->amount)->toBe(-8)
        ->and($capture->meta['usage'])->toBe(['ai.input_tokens' => 2_500, 'ai.output_tokens' => 1_200])
        ->and($wallet->meter_remainders)->toBe(['ai.input_tokens' => 500]);
});

it('finds a pending hold by its meta, as the laravel/ai recipe does', function () {
    $wallet = $this->team()->credits();
    $wallet->grant(100);
    $wallet->hold(30, 'AI turn', key: 'ai:inv-1', meta: ['invocation' => 'inv-1']);
    $wallet->hold(20, 'AI turn', key: 'ai:inv-2', meta: ['invocation' => 'inv-2']);

    Hold::query()->where('meta->invocation', 'inv-2')->where('status', 'pending')->first()?->settle(5);

    expect($wallet->available())->toBe(65);
});

it('keeps what a hold reserves when its lot expires, even with fresh credits beside it', function () {
    $wallet = $this->team()->credits();
    $wallet->grant(80, expiresAt: now()->addHour());
    $hold = $wallet->hold(80, ttl: 7200);
    $wallet->grant(50, 'Top-up');

    $this->travel(90)->minutes();

    // The hold reserved the expiring lot; the top-up bought afterwards is still there to spend.
    expect($wallet->available())->toBe(50)->and($wallet->total())->toBe(130);

    $wallet->spend(50);
    $hold->settle(50);

    // The hold was charged from its expired lot; the 30 it gave back expired right away.
    expect($wallet->total())->toBe(0)->and($wallet->balance)->toBe(0);
    $this->artisan('credits:audit')->assertSuccessful();
});

it('charges a hold across the new period from the old period\'s allowance it reserved', function () {
    $this->travelTo(now()->setDate(2026, 10, 31)->setTime(23, 55));
    Credits::allowancesUsing(fn () => [Allowance::monthly(1_000)]);

    $wallet = $this->team()->credits();
    $hold = $wallet->hold(200, 'AI turn');

    $this->travel(10)->minutes();
    $hold->settle(150);

    // October's 1,000 paid for the turn (its other 850 expired with the month); November's allowance is untouched.
    expect($wallet->available())->toBe(1_000)
        ->and((int) $wallet->transactions()->ofType(TransactionType::Expire)->sum('amount'))->toBe(-850);
});

it('expires what a released hold gave back of a lot that ran out under it', function () {
    $wallet = $this->team()->credits();
    $wallet->grant(40, expiresAt: now()->addMinutes(5));
    $wallet->grant(10);
    $hold = $wallet->hold(40);

    $this->travel(10)->minutes();
    $hold->release();

    expect($wallet->balance)->toBe(10)->and($wallet->available())->toBe(10);
});

it('extends a hold for work still running, never shortening it', function () {
    $wallet = $this->team()->credits();
    $wallet->grant(100);
    $hold = $wallet->hold(30, ttl: 60);

    $this->travel(50)->seconds();
    $hold->extend(120);

    expect($hold->expires_at->toDateTimeString())->toBe(now()->addSeconds(120)->toDateTimeString());

    $hold->extend(10);

    expect($hold->expires_at->toDateTimeString())->toBe(now()->addSeconds(120)->toDateTimeString());

    $this->travel(100)->seconds();

    expect($wallet->available())->toBe(70)->and($hold->fresh()->isPending())->toBeTrue();
});

it('refuses to extend a hold that is over', function () {
    $wallet = $this->team()->credits();
    $wallet->grant(100);
    $expired = $wallet->hold(30, ttl: 60);
    $settled = $wallet->hold(30);
    $settled->settle(10);

    $this->travel(2)->minutes();

    expect(fn () => $expired->extend())->toThrow(HoldNotPending::class)
        ->and(fn () => $settled->extend())->toThrow(HoldNotPending::class);
});

it('refuses a hold that would never live', function () {
    $wallet = $this->team()->credits();
    $wallet->grant(100);

    expect(fn () => $wallet->hold(10, ttl: 0))->toThrow(InvalidAmount::class)
        ->and(fn () => $wallet->hold(10, ttl: -5))->toThrow(InvalidAmount::class)
        ->and($wallet->available())->toBe(100);
});

it('links a hold to its ledger lines and its settlement', function () {
    $wallet = $this->team()->credits();
    $wallet->grant(100);
    $hold = $wallet->hold(30);
    $pending = $wallet->hold(10);

    $capture = $hold->settle(12);

    expect($hold->transactions()->orderBy('id')->pluck('type')->map->value->all())->toBe(['hold', 'capture'])
        ->and($hold->settlement->id)->toBe($capture->id)
        ->and($hold->isSettled())->toBeTrue()
        ->and($wallet->holds()->pending()->pluck('id')->all())->toBe([$pending->id]);
});

it('settles several holds in any order without touching each other\'s reservation', function () {
    $wallet = $this->team()->credits();
    $wallet->grant(30, expiresAt: now()->addDays(1));
    $wallet->grant(100);

    $a = $wallet->hold(40);
    $b = $wallet->hold(50);

    $b->settle(70);
    expect($wallet->available())->toBe(130 - 70 - 40);

    $a->settle(10);
    expect($wallet->available())->toBe(130 - 70 - 10)->and($wallet->held)->toBe(0);

    $this->artisan('credits:audit')->assertSuccessful();
});
