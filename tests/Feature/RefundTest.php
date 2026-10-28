<?php

use Packstub\Credits\Enums\TransactionType;
use Packstub\Credits\Exceptions\InvalidAmount;
use Packstub\Credits\Models\Grant;

it('gives a refund back to the lots the credits came from, keeping their expiry', function () {
    $wallet = $this->team()->credits();
    $wallet->grant(10, expiresAt: now()->addDays(3), source: 'promo');
    $wallet->grant(50, source: 'top-up');
    $spend = $wallet->spend(30);

    $refund = $wallet->refund($spend, reason: 'Export failed');

    expect($refund->type)->toBe(TransactionType::Refund)
        ->and($refund->amount)->toBe(30)
        ->and($refund->parent_id)->toBe($spend->id)
        ->and(Grant::query()->orderBy('id')->pluck('remaining', 'source')->all())->toBe(['promo' => 10, 'top-up' => 50])
        ->and($spend->refundable())->toBe(0);
});

it('unwinds partial refunds from the end so no credit comes back twice', function () {
    $wallet = $this->team()->credits();
    $wallet->grant(10, expiresAt: now()->addDays(3), source: 'promo');
    $wallet->grant(50, source: 'top-up');
    $spend = $wallet->spend(30); // 10 from promo, then 20 from top-up

    $wallet->refund($spend, 15);
    expect(Grant::query()->orderBy('id')->pluck('remaining', 'source')->all())->toBe(['promo' => 0, 'top-up' => 45]);

    $wallet->refund($spend, 10);
    expect(Grant::query()->orderBy('id')->pluck('remaining', 'source')->all())->toBe(['promo' => 5, 'top-up' => 50]);

    expect($spend->refundable())->toBe(5)
        ->and(fn () => $wallet->refund($spend, 6))->toThrow(InvalidAmount::class);
});

it('returns credits of a lot that expired meanwhile as a new lot with a grace period', function () {
    config(['packstub-credits.refund_grace_days' => 7]);

    $wallet = $this->team()->credits();
    $wallet->grant(20, expiresAt: now()->addDay());
    $spend = $wallet->spend(20);

    $this->travel(3)->days();
    $wallet->refund($spend);

    $lot = Grant::query()->where('source', 'refund')->sole();

    expect($wallet->available())->toBe(20)
        ->and($lot->expires_at->toDateString())->toBe(now()->addDays(7)->toDateString());
});

it('refunds a capture too, but not a grant', function () {
    $wallet = $this->team()->credits();
    $grant = $wallet->grant(100);
    $capture = $wallet->hold(40)->settle(40);

    $wallet->refund($capture, 40);

    expect($wallet->available())->toBe(100)
        ->and(fn () => $wallet->refund($grant))->toThrow(InvalidAmount::class);
});

it('replays a retried refund by its key', function () {
    $wallet = $this->team()->credits();
    $wallet->grant(100);
    $spend = $wallet->spend(40);

    $first = $wallet->refund($spend, key: 'refund:ticket-77');
    $again = $wallet->refund($spend, key: 'refund:ticket-77');

    expect($again->id)->toBe($first->id)->and($wallet->available())->toBe(100);
});

it('refuses a refund of another wallet\'s spend', function () {
    $acme = $this->team()->credits();
    $globex = $this->team('Globex')->credits();
    $acme->grant(10);
    $spend = $acme->spend(10);

    expect(fn () => $globex->refund($spend))->toThrow(InvalidAmount::class);
});
