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
        ->and($wallet->transactions()->orderBy('id')->pluck('type')->map->value->all())->toBe(['grant', 'spend', 'grant', 'expire']);
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

it('grants the difference at once when the plan grows mid-period, once', function () {
    $team = $this->team();
    $wallet = $team->credits();
    $wallet->spend(400);

    Credits::allowancesUsing(fn (Team $team) => [Allowance::monthly($team->plan === 'business' ? 5_000 : 1_000)]);
    $team->update(['plan' => 'business']);

    expect($wallet->available())->toBe(600 + 4_000)
        ->and($wallet->available())->toBe(4_600)
        ->and(Grant::query()->orderBy('id')->pluck('amount')->all())->toBe([1_000, 4_000])
        ->and(Grant::query()->orderBy('id')->pluck('expires_at')->map->toDateTimeString()->unique()->all())->toBe(['2026-11-01 00:00:00']);
});

it('keeps what the period granted when the plan shrinks, and never grants an upgrade twice', function () {
    $team = $this->team();
    Credits::allowancesUsing(fn (Team $team) => [Allowance::monthly(['pro' => 1_000, 'starter' => 200, 'business' => 5_000][$team->plan])]);
    $wallet = $team->credits();
    $wallet->available();

    $team->update(['plan' => 'starter']);
    expect($wallet->available())->toBe(1_000);

    $team->update(['plan' => 'pro']);
    expect($wallet->available())->toBe(1_000);

    $team->update(['plan' => 'business']);
    expect($wallet->available())->toBe(5_000);

    $team->update(['plan' => 'starter']);
    $this->travelTo(CarbonImmutable::parse('2026-11-05 10:00:00'));

    expect($wallet->available())->toBe(200);
});

it('rolls unused credits over for a number of periods', function () {
    Credits::allowancesUsing(fn () => [Allowance::monthly(100)->rollover(2)]);
    $wallet = $this->team()->credits();
    $wallet->available();

    expect(Grant::query()->sole()->expires_at->toDateTimeString())->toBe('2027-01-01 00:00:00');

    $this->travelTo(CarbonImmutable::parse('2026-12-15 10:00:00'));
    expect($wallet->available())->toBe(200);

    // October's lot lived two more periods and expired on 1 January; December's is still there beside January's.
    $this->travelTo(CarbonImmutable::parse('2027-01-02 10:00:00'));
    expect($wallet->available())->toBe(200)
        ->and((int) $wallet->transactions()->where('type', 'expire')->sum('amount'))->toBe(-100);
});

it('rolls over for a number of anchored periods, short months included', function () {
    $allowance = Allowance::monthly(100)->anchoredAt(CarbonImmutable::parse('2026-01-31 00:00:00'))->rollover(1);
    Credits::allowancesUsing(fn () => [$allowance]);

    $this->travelTo(CarbonImmutable::parse('2026-02-10 10:00:00'));
    $this->team()->credits()->available();

    expect(Grant::query()->sole()->expires_at->toDateTimeString())->toBe('2026-03-31 00:00:00');
});

it('grants nothing before the anchor', function () {
    Credits::allowancesUsing(fn () => [Allowance::monthly(100)->anchoredAt(CarbonImmutable::parse('2026-11-15 00:00:00'))]);
    $wallet = $this->team()->credits();

    expect($wallet->available())->toBe(0);

    $this->travelTo(CarbonImmutable::parse('2026-11-15 00:00:00'));

    expect($wallet->available())->toBe(100);
});

it('refuses two allowances under one name', function () {
    Credits::allowancesUsing(fn () => [Allowance::monthly(100), Allowance::weekly(10)]);

    expect(fn () => $this->team()->credits()->available())->toThrow(InvalidArgumentException::class, 'share a name');
});

it('grants daily and yearly allowances on their own calendars', function () {
    Credits::allowancesUsing(fn () => [Allowance::daily(5)->named('daily'), Allowance::yearly(1_000)->named('yearly')]);
    $wallet = $this->team()->credits();

    expect($wallet->available())->toBe(1_005);

    $this->travelTo(CarbonImmutable::parse('2026-10-22 00:00:00'));
    expect($wallet->available())->toBe(1_005);

    $this->travelTo(CarbonImmutable::parse('2027-01-01 00:00:00'));
    expect($wallet->available())->toBe(1_005)
        ->and(Grant::query()->where('meta->allowance', 'yearly')->latest('id')->first()->expires_at->toDateTimeString())->toBe('2028-01-01 00:00:00');
});

it('says what the period still owes a wallet', function () {
    $allowance = Allowance::monthly(1_000);
    $wallet = $this->team()->credits();

    Credits::allowancesUsing(null);
    expect($allowance->owed($wallet))->toBe(1_000);

    Credits::allowancesUsing(fn () => [$allowance]);
    $wallet->available();

    expect($allowance->owed($wallet))->toBe(0);
});

it('refuses an allowance of nothing or a negative rollover', function () {
    expect(fn () => Allowance::monthly(0))->toThrow(InvalidArgumentException::class)
        ->and(fn () => Allowance::monthly(10)->rollover(-1))->toThrow(InvalidArgumentException::class);
});
