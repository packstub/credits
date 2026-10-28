<?php

use Illuminate\Support\Facades\Event;
use Packstub\Credits\Events\Shortfall;
use Packstub\Credits\Exceptions\InvalidAmount;
use Packstub\Credits\Exceptions\UnknownMeter;
use Packstub\Credits\Facades\Credits;
use Packstub\Credits\Tests\Fixtures\Models\Team;

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

it('refuses negative, missing or empty usage instead of handing out credits', function (array $usage) {
    $wallet = $this->team()->credits();
    $wallet->grant(10);

    expect(fn () => $wallet->meter($usage))->toThrow(InvalidAmount::class)
        ->and($wallet->available())->toBe(10)
        ->and($wallet->meter_remainders)->toBeNull();

    $this->artisan('credits:audit')->assertSuccessful();
})->with([
    'negative' => [['sms' => -5]],
    'negative among others' => [['ai.input_tokens' => 2_000, 'ai.output_tokens' => -1]],
    'missing' => [['sms' => null]],
    'a string' => [['sms' => '3']],
    'not a number' => [['sms' => NAN]],
    'empty' => [[]],
]);

it('refuses a meter called without a quantity', function () {
    $wallet = $this->team()->credits();
    $wallet->grant(10);

    expect(fn () => $wallet->meter('sms'))->toThrow(InvalidAmount::class);
});

it('charges nothing for zero usage', function () {
    $wallet = $this->team()->credits();
    $wallet->grant(10);

    expect($wallet->meter('sms', 0))->toBeNull()->and($wallet->available())->toBe(10);
});

it('rounds a fractional quantity to the unit', function () {
    $wallet = $this->team()->credits();
    $wallet->grant(10);

    $wallet->meter('sms', 1.6);

    expect($wallet->available())->toBe(6);
});

it('refuses a meter whose rate is misconfigured', function (array $rate) {
    config(['packstub-credits.meters.bad' => $rate]);
    $wallet = $this->team()->credits();
    $wallet->grant(10);

    expect(fn () => $wallet->meter('bad', 1))->toThrow(UnknownMeter::class, 'needs a whole number');
})->with([
    'negative credits' => [['credits' => -1]],
    'per zero' => [['credits' => 1, 'per' => 0]],
    'a float' => [['credits' => 0.5]],
]);

it('quotes what usage would cost now without charging it', function () {
    $wallet = $this->team()->credits();
    $wallet->grant(100);
    $wallet->meter('ai.input_tokens', 600);

    expect($wallet->quote('ai.input_tokens', 600))->toBe(1)
        ->and($wallet->quote(['ai.input_tokens' => 300, 'ai.output_tokens' => 1_000]))->toBe(5)
        ->and($wallet->available())->toBe(100)
        ->and($wallet->meter_remainders)->toBe(['ai.input_tokens' => 600]);
});

it('prices usage per holder over the configured rates', function () {
    Credits::metersUsing(fn (Team $team, string $wallet) => $team->plan === 'enterprise' ? ['sms' => ['credits' => 1]] : []);

    $pro = $this->team('Pro')->credits();
    $enterprise = $this->team('Big');
    $enterprise->update(['plan' => 'enterprise']);
    $enterprise = $enterprise->credits();
    $pro->grant(100);
    $enterprise->grant(100);

    $pro->meter(['sms' => 10, 'ai.output_tokens' => 1_000]);
    $enterprise->meter(['sms' => 10, 'ai.output_tokens' => 1_000]);

    expect($pro->available())->toBe(100 - 20 - 5)
        ->and($enterprise->available())->toBe(100 - 10 - 5);
});
