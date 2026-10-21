<?php

use Illuminate\Support\Facades\Event;
use Packstub\Credits\Events\Shortfall;
use Packstub\Credits\Exceptions\UnknownMeter;

it('carries fractions of a credit to the next call instead of rounding them away', function () {
    $wallet = $this->team()->credits();
    $wallet->grant(100);

    $charges = collect([400, 400, 400, 400, 400])->map(fn (int $tokens) => $wallet->meter('ai.input_tokens', $tokens)?->amount);

    // 2,000 tokens at 1 credit per 1,000, charged as they add up: exactly 2 credits, no more, no less.
    expect($charges->all())->toBe([null, null, -1, null, -1])
        ->and($wallet->available())->toBe(98)
        ->and($wallet->meter_remainders)->toBeEmpty();
});

it('charges many small calls exactly what one call of their total costs', function () {
    $small = $this->team('Small')->credits();
    $big = $this->team('Big')->credits();
    $small->grant(1_000);
    $big->grant(1_000);

    foreach (range(1, 37) as $call) {
        $small->meter(['ai.input_tokens' => 1_234, 'ai.output_tokens' => 321]);
    }

    $big->meter(['ai.input_tokens' => 1_234 * 37, 'ai.output_tokens' => 321 * 37]);

    expect($small->available())->toBe($big->available())
        ->and($small->meter_remainders)->toBe($big->meter_remainders);
});

it('charges what the wallet has for usage that already happened', function () {
    Event::fake([Shortfall::class]);

    $wallet = $this->team()->credits();
    $wallet->grant(3);

    $spend = $wallet->meter('sms', 4);

    expect($spend->amount)->toBe(-3)
        ->and($spend->meta['shortfall'])->toBe(5)
        ->and($wallet->available())->toBe(0);

    Event::assertDispatched(Shortfall::class, fn (Shortfall $e) => $e->amount === 5 && $e->hold === null);
});

it('does not carry a fraction twice when a keyed call is retried', function () {
    $wallet = $this->team()->credits();
    $wallet->grant(10);

    $wallet->meter('ai.input_tokens', 600, key: 'call:1');
    $wallet->meter('ai.input_tokens', 600, key: 'call:1');

    expect($wallet->meter_remainders)->toBe(['ai.input_tokens' => 600])->and($wallet->available())->toBe(10);
});

it('refuses a meter without a rate', function () {
    $wallet = $this->team()->credits();

    expect(fn () => $wallet->meter('ai.images', 1))->toThrow(UnknownMeter::class);
});
