<?php

use Illuminate\Support\Facades\Event;
use Packstub\Credits\Enums\TransactionType;
use Packstub\Credits\Events\TransactionRecorded;
use Packstub\Credits\Exceptions\IdempotencyConflict;
use Packstub\Credits\Exceptions\InsufficientCredits;
use Packstub\Credits\Exceptions\InvalidAmount;
use Packstub\Credits\Facades\Credits;
use Packstub\Credits\Models\Grant;
use Packstub\Credits\Support\Allowance;
use Packstub\Credits\Tests\Fixtures\Models\Team;

it('moves credits between two wallets, each with its own ledger line', function () {
    $pool = $this->team('Acme')->credits();
    $member = $this->team('Member')->credits();
    $pool->grant(100);

    $sent = $pool->transfer($member, 30, 'Monthly share');

    $received = $member->transactions()->sole();

    expect($pool->available())->toBe(70)
        ->and($member->available())->toBe(30)
        ->and($sent->type)->toBe(TransactionType::Transfer)
        ->and($sent->amount)->toBe(-30)
        ->and($sent->meta['to_wallet'])->toBe($member->id)
        ->and($received->amount)->toBe(30)
        ->and($received->parent_id)->toBe($sent->id)
        ->and($received->meta['from_wallet'])->toBe($pool->id);

    $this->artisan('credits:audit')->assertSuccessful();
});

it('keeps the credits\' expiry, one lot per expiry they had', function () {
    $pool = $this->team('Acme')->credits();
    $member = $this->team('Member')->credits('sms');
    $pool->grant(10, expiresAt: now()->addDays(2)->startOfSecond());
    $pool->grant(10, expiresAt: now()->addDays(2)->startOfSecond());
    $pool->grant(50);

    $pool->transfer($member, 35);

    $lots = Grant::query()->where('wallet_id', $member->id)->orderBy('id')->get();

    expect($lots->map(fn (Grant $lot) => [$lot->amount, $lot->expires_at?->toDateTimeString()])->all())->toBe([
        [20, now()->addDays(2)->toDateTimeString()],
        [15, null],
    ]);

    $this->travel(3)->days();

    expect($member->available())->toBe(15);
});

it('does not move credits a hold reserves', function () {
    $pool = $this->team('Acme')->credits();
    $member = $this->team('Member')->credits();
    $pool->grant(100);
    $pool->hold(80);

    expect(fn () => $pool->transfer($member, 21))->toThrow(InsufficientCredits::class);

    $pool->transfer($member, 20);

    expect($pool->available())->toBe(0)->and($pool->total())->toBe(80)->and($member->available())->toBe(20);
});

it('replays a transfer by its key and refuses the key for another receiver', function () {
    $pool = $this->team('Acme')->credits();
    $member = $this->team('Member')->credits();
    $other = $this->team('Other')->credits();
    $pool->grant(100);

    $first = $pool->transfer($member, 25, key: 'share:2026-10');
    $again = $pool->transfer($member, 25, key: 'share:2026-10');

    expect($again->id)->toBe($first->id)
        ->and($member->available())->toBe(25)
        ->and(fn () => $pool->transfer($other, 25, key: 'share:2026-10'))->toThrow(IdempotencyConflict::class);
});

it('refuses a transfer to the same wallet or of nothing', function () {
    $wallet = $this->team()->credits();
    $wallet->grant(10);

    expect(fn () => $wallet->transfer($wallet, 5))->toThrow(InvalidAmount::class)
        ->and(fn () => $wallet->transfer($this->team('Other')->credits(), 0))->toThrow(InvalidAmount::class);
});

it('records both sides after commit and neither on failure', function () {
    Event::fake([TransactionRecorded::class]);

    $pool = $this->team('Acme')->credits();
    $member = $this->team('Member')->credits();
    $pool->grant(10);

    expect(fn () => $pool->transfer($member, 11))->toThrow(InsufficientCredits::class);
    Event::assertDispatchedTimes(TransactionRecorded::class, 1);

    $pool->transfer($member, 10);
    Event::assertDispatchedTimes(TransactionRecorded::class, 3);
});

it('grants the receiver\'s allowance before the transfer lands', function () {
    Credits::allowancesUsing(fn (Team $team) => $team->name === 'Member' ? [Allowance::monthly(5)] : []);

    $pool = $this->team('Acme')->credits();
    $member = $this->team('Member')->credits();
    $pool->grant(10);

    $pool->transfer($member, 10);

    expect($member->balance)->toBe(15);
});
