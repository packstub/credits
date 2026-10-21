<?php

use Carbon\CarbonImmutable;
use Packstub\Credits\Facades\Credits;
use Packstub\Credits\Models\Grant;
use Packstub\Credits\Support\Allowance;
use Packstub\Credits\Tests\Fixtures\Models\Team;

beforeEach(function () {
    $this->travelTo(CarbonImmutable::parse('2026-10-21 09:00:00'));

    Credits::allowancesUsing(fn (Team $team, string $wallet) => match ([$team->plan, $wallet]) {
        ['pro', 'default'] => [Allowance::monthly(1_000)],
        ['team', 'default'] => [Allowance::monthly(500)->rollover(), Allowance::weekly(50)->named('bonus')],
        default => [],
    });
});

it('grants the current period\'s allowance on first touch, once', function () {
    $wallet = $this->team()->credits();

    expect($wallet->available())->toBe(1_000)
        ->and($wallet->available())->toBe(1_000)
        ->and(Grant::query()->sole()->expires_at->toDateTimeString())->toBe('2026-11-01 00:00:00');
});

it('lets the unused allowance expire and grants the next period\'s', function () {
    $wallet = $this->team()->credits();
    $wallet->spend(300);

    $this->travelTo(CarbonImmutable::parse('2026-11-03 12:00:00'));

    expect($wallet->available())->toBe(1_000)
        ->and($wallet->transactions()->pluck('type')->map->value->all())->toBe(['grant', 'spend', 'grant', 'expire']);
});

it('rolls unused credits over when the allowance says so', function () {
    $team = $this->team();
    $team->update(['plan' => 'team']);
    $wallet = $team->credits();
    $wallet->spend(100);

    $this->travelTo(CarbonImmutable::parse('2026-11-02 08:00:00'));

    // The spend took the weekly bonus first (it expired soonest), then 50 of October's 500, which rolls over:
    // 450 left, November's 500 and the new week's bonus on top.
    expect($wallet->available())->toBe(450 + 500 + 50);
});

it('gives a wallet nobody touched for months only the current period', function () {
    $wallet = $this->team()->credits();
    $wallet->available();

    $this->travelTo(CarbonImmutable::parse('2027-02-10 10:00:00'));

    expect($wallet->available())->toBe(1_000)
        ->and($wallet->grants()->count())->toBe(2);
});

it('renews on the anchor\'s anniversaries without overflowing short months', function () {
    $allowance = Allowance::monthly(10)->anchoredAt(CarbonImmutable::parse('2026-01-31 15:00:00'));

    [$start, $end] = $allowance->period(CarbonImmutable::parse('2026-02-28 16:00:00'));
    expect($start->toDateTimeString())->toBe('2026-02-28 15:00:00')->and($end->toDateTimeString())->toBe('2026-03-31 15:00:00');

    [$start] = $allowance->period(CarbonImmutable::parse('2026-02-28 14:00:00'));
    expect($start->toDateTimeString())->toBe('2026-01-31 15:00:00');

    [$start, $end] = $allowance->period(CarbonImmutable::parse('2026-10-21 09:00:00'));
    expect($start->toDateTimeString())->toBe('2026-09-30 15:00:00')->and($end->toDateTimeString())->toBe('2026-10-31 15:00:00');
});

it('grants nothing to a plan without allowances', function () {
    $team = $this->team();
    $team->update(['plan' => 'free']);

    expect($team->credits()->available())->toBe(0);
});
