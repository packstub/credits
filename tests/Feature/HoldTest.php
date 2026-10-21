<?php

use Illuminate\Support\Facades\Event;
use Packstub\Credits\Enums\HoldStatus;
use Packstub\Credits\Events\Shortfall;
use Packstub\Credits\Exceptions\HoldNotPending;
use Packstub\Credits\Exceptions\InsufficientCredits;
use Packstub\Credits\Models\Hold;

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
