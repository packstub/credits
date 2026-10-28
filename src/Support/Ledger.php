<?php

namespace Packstub\Credits\Support;

use Carbon\CarbonInterface;
use Closure;
use Illuminate\Support\Facades\DB;
use Packstub\Credits\Enums\HoldStatus;
use Packstub\Credits\Enums\TransactionType;
use Packstub\Credits\Events\BalanceLow;
use Packstub\Credits\Events\Shortfall;
use Packstub\Credits\Events\TransactionRecorded;
use Packstub\Credits\Exceptions\HoldNotPending;
use Packstub\Credits\Exceptions\IdempotencyConflict;
use Packstub\Credits\Exceptions\InsufficientCredits;
use Packstub\Credits\Exceptions\InvalidAmount;
use Packstub\Credits\Facades\Credits;
use Packstub\Credits\Models\Grant;
use Packstub\Credits\Models\Hold;
use Packstub\Credits\Models\Transaction;
use Packstub\Credits\Models\Wallet;
use Throwable;

/**
 * Every change to a wallet, each in one database transaction under a lock
 * on the wallet's row, so two workers spending at once see each other's
 * spends. Before any operation the wallet catches up: due lots expire,
 * stale holds are released and the current period's allowances are granted,
 * so nothing depends on a cron having run. `credits:expire` does the same
 * for wallets nobody touches, so their events still fire on time.
 *
 * Pending holds reserve the wallet's soonest-expiring credits, the ones the
 * work would have been charged from had it been charged up front: a capture
 * takes from the front of the spend order, every other take skips the first
 * `held` credits, and a lot that expires under a hold keeps what the hold
 * reserves until it is settled or released.
 */
final class Ledger
{
    public static function grant(Wallet $wallet, int $amount, ?string $reason, ?CarbonInterface $expiresAt, string $source, ?string $key, array $meta, TransactionType $type = TransactionType::Grant): Transaction
    {
        self::positive($amount);
        self::text($key, $reason, $source);

        return self::locked($wallet, function (Wallet $wallet) use ($amount, $reason, $expiresAt, $source, $key, $meta, $type) {
            if ($replay = self::replay($wallet, $key, $type, fn (Transaction $t) => $t->amount === $amount)) {
                return $replay;
            }

            return self::addLot($wallet, $type, $amount, $reason, $expiresAt, $source, $key, $meta);
        });
    }

    public static function spend(Wallet $wallet, int $amount, ?string $reason, ?string $key, array $meta, TransactionType $type = TransactionType::Spend): Transaction
    {
        self::positive($amount);
        self::text($key, $reason);

        return self::locked($wallet, function (Wallet $wallet) use ($amount, $reason, $key, $meta, $type) {
            if ($replay = self::replay($wallet, $key, $type, fn (Transaction $t) => $t->amount === -$amount)) {
                return $replay;
            }

            if ($amount > $available = $wallet->balance - $wallet->held) {
                throw new InsufficientCredits($wallet, $amount, $available);
            }

            return self::take($wallet, $type, $amount, $reason, $key, $meta);
        });
    }

    public static function adjust(Wallet $wallet, int $amount, string $reason, ?string $key, array $meta, ?CarbonInterface $expiresAt = null): Transaction
    {
        // The one integer whose negation is not an integer.
        if ($amount === PHP_INT_MIN) {
            throw InvalidAmount::outOfRange($amount);
        }

        return $amount >= 0
            ? self::grant($wallet, $amount, $reason, $expiresAt, 'adjustment', $key, $meta, TransactionType::Adjust)
            : self::spend($wallet, -$amount, $reason, $key, $meta, TransactionType::Adjust);
    }

    public static function hold(Wallet $wallet, int $amount, ?string $reason, ?int $ttl, ?string $key, array $meta): Hold
    {
        self::positive($amount);
        self::text($key, $reason);
        $ttl = self::ttl($ttl);

        // The hold keeps the caller's wallet, so settling it later updates the model the caller holds.
        return self::locked($wallet, function (Wallet $locked) use ($wallet, $amount, $reason, $ttl, $key, $meta) {
            return self::placeHold($locked, $amount, $reason, $ttl, $key, $meta)->setRelation('wallet', $wallet);
        });
    }

    private static function placeHold(Wallet $wallet, int $amount, ?string $reason, int $ttl, ?string $key, array $meta): Hold
    {
        if ($replay = self::replay($wallet, $key, TransactionType::Hold, fn (Transaction $t) => $t->held_change === $amount)) {
            return $replay->hold;
        }

        if ($amount > $available = $wallet->balance - $wallet->held) {
            throw new InsufficientCredits($wallet, $amount, $available);
        }

        $hold = $wallet->holds()->create([
            'amount' => $amount,
            'status' => HoldStatus::Pending,
            'reason' => $reason,
            'expires_at' => now()->addSeconds($ttl),
            'meta' => $meta ?: null,
        ]);

        $wallet->held += $amount;

        self::record($wallet, TransactionType::Hold, 0, $reason, $key, $meta, heldChange: $amount, hold: $hold);

        return $hold;
    }

    /**
     * @param  array<string, int|float>|null  $usage  priced by the meters instead of a fixed amount
     */
    public static function capture(Hold $hold, ?int $amount, bool $strict, ?array $usage = null): Transaction
    {
        if ($amount !== null && $amount < 0) {
            throw InvalidAmount::negative($amount);
        }

        return self::locked($hold->wallet, function (Wallet $wallet) use ($hold, $amount, $strict, $usage) {
            $fresh = Hold::query()->whereKey($hold->getKey())->firstOrFail();

            // Work that ran past its hold's lifetime still gets settled: the reservation is gone, so the charge
            // draws on what is available now, like a meter would.
            $late = ! $strict && $fresh->status === HoldStatus::Expired;

            if (! $fresh->isPending() && ! $late) {
                throw new HoldNotPending($fresh);
            }

            $reserved = $late ? 0 : $fresh->amount;
            $meta = $late ? ['late' => true] : [];

            if ($usage !== null) {
                [$amount, $remainders] = Meters::price($wallet, $usage);
                $wallet->meter_remainders = $remainders;
                $meta['usage'] = $usage;
            }

            // What this hold may draw on: its own reservation plus whatever nobody else has reserved.
            $coverable = max(0, $wallet->balance - ($wallet->held - $reserved));

            if ($strict && $amount > $coverable) {
                throw new InsufficientCredits($wallet, $amount, $coverable);
            }

            $charge = min($amount, $coverable);
            $shortfall = $amount - $charge;

            $fresh->forceFill(['status' => HoldStatus::Captured, 'captured' => $charge, 'shortfall' => $shortfall])->save();
            $hold->setRawAttributes($fresh->getAttributes(), true);

            $wallet->held -= $reserved;

            if ($shortfall > 0) {
                $meta['shortfall'] = $shortfall;
                self::later(new Shortfall($wallet, $fresh, $shortfall));
            }

            // A pending hold is charged from the reserved credits at the front of the spend order; a late one,
            // whose reservation is gone, from the credits nobody else reserves.
            return self::take($wallet, TransactionType::Capture, $charge, $fresh->reason, null, $meta, heldChange: -$reserved, hold: $fresh, skip: $late ? null : 0);
        });
    }

    public static function release(Hold $hold): Transaction
    {
        return self::locked($hold->wallet, function (Wallet $wallet) use ($hold) {
            $fresh = self::pending($hold);
            $transaction = self::releaseHold($wallet, $fresh, $fresh->reason);
            $hold->setRawAttributes($fresh->getAttributes(), true);

            return $transaction;
        });
    }

    public static function extend(Hold $hold, ?int $ttl): Hold
    {
        $ttl = self::ttl($ttl);

        return self::locked($hold->wallet, function () use ($hold, $ttl) {
            $fresh = self::pending($hold);

            if (($until = now()->addSeconds($ttl))->gt($fresh->expires_at)) {
                $fresh->forceFill(['expires_at' => $until])->save();
            }

            $hold->setRawAttributes($fresh->getAttributes(), true);

            return $hold;
        });
    }

    /** @param  array<string, int|float>  $usage */
    public static function meter(Wallet $wallet, array $usage, ?string $reason, ?string $key, array $meta): ?Transaction
    {
        self::text($key, $reason);

        return self::locked($wallet, function (Wallet $wallet) use ($usage, $reason, $key, $meta) {
            if ($replay = self::replay($wallet, $key, TransactionType::Spend, fn (Transaction $t) => ($t->meta['usage'] ?? null) == $usage)) {
                return $replay;
            }

            [$amount, $remainders] = Meters::price($wallet, $usage);
            $wallet->meter_remainders = $remainders;

            $meta['usage'] = $usage;

            // Below one credit so far: the fraction is carried, nothing is charged yet. With a key the call
            // leaves a zero line, so a retry finds it and does not carry the fraction twice.
            if ($amount === 0) {
                $wallet->save();

                return $key === null ? null : self::record($wallet, TransactionType::Spend, 0, $reason, $key, $meta);
            }

            $available = max(0, $wallet->balance - $wallet->held);
            $charge = min($amount, $available);

            if ($charge < $amount) {
                $meta['shortfall'] = $amount - $charge;
                self::later(new Shortfall($wallet, null, $amount - $charge));
            }

            return self::take($wallet, TransactionType::Spend, $charge, $reason, $key, $meta);
        });
    }

    public static function refund(Wallet $wallet, Transaction $spend, ?int $amount, ?string $reason, ?string $key): Transaction
    {
        if ($spend->wallet_id !== $wallet->id || ! $spend->type->isRefundable()) {
            throw InvalidAmount::notRefundable($spend);
        }

        self::text($key, $reason);

        return self::locked($wallet, function (Wallet $wallet) use ($spend, $amount, $reason, $key) {
            if ($replay = self::replay($wallet, $key, TransactionType::Refund, fn (Transaction $t) => $t->parent_id === $spend->id && ($amount === null || $t->amount === $amount))) {
                return $replay;
            }

            $refundable = $spend->refundable();
            $amount ??= $refundable;

            self::positive($amount);

            if ($amount > $refundable) {
                throw InvalidAmount::overRefund($spend, $amount, $refundable);
            }

            // Refunds unwind the spend from its end: the lots it took from last are given back first, so two
            // partial refunds never return the same credits twice.
            $skip = -$spend->amount - $refundable;
            $left = $amount;
            $allocations = [];

            foreach ($spend->allocations()->with('grant')->orderByDesc('id')->get() as $allocation) {
                $taken = -$allocation->amount;

                if ($skip >= $taken) {
                    $skip -= $taken;

                    continue;
                }

                $back = min($taken - $skip, $left);
                $skip = 0;
                $left -= $back;
                $grant = $allocation->grant;

                if ($grant->isExpired()) {
                    $grant = $wallet->grants()->create([
                        'amount' => $back,
                        'remaining' => $back,
                        'source' => 'refund',
                        'reason' => $reason,
                        'expires_at' => now()->addDays((int) config('packstub-credits.refund_grace_days', 7)),
                        'meta' => ['refunded_from_grant' => $allocation->grant_id],
                    ]);
                } else {
                    $grant->increment('remaining', $back);
                }

                $allocations[] = [$grant->id, $back];

                if ($left === 0) {
                    break;
                }
            }

            self::credit($wallet, $amount);

            return self::record($wallet, TransactionType::Refund, $amount, $reason ?? $spend->reason, $key, [], allocations: $allocations, parent: $spend);
        });
    }

    /**
     * Move credits between two wallets in one database transaction, both locked. The receiver gets one lot per
     * expiry the credits had, so a transfer never extends or shortens their life.
     */
    public static function transfer(Wallet $from, Wallet $to, int $amount, ?string $reason, ?string $key, array $meta): Transaction
    {
        self::positive($amount);
        self::text($key, $reason);

        if ($from->getKey() === $to->getKey()) {
            throw InvalidAmount::sameWallet();
        }

        return self::locked([$from, $to], function (Wallet $from, Wallet $to) use ($amount, $reason, $key, $meta) {
            if ($replay = self::replay($from, $key, TransactionType::Transfer, fn (Transaction $t) => $t->amount === -$amount && ($t->meta['to_wallet'] ?? null) === $to->id)) {
                return $replay;
            }

            if ($amount > $available = $from->balance - $from->held) {
                throw new InsufficientCredits($from, $amount, $available);
            }

            $taken = self::drain($from, $amount, $from->held);
            $from->balance -= $amount;
            $sent = self::record($from, TransactionType::Transfer, -$amount, $reason, $key, ['to_wallet' => $to->id] + $meta, allocations: $taken);

            $lots = [];

            foreach ($taken as [, $credits, $grant]) {
                $expiry = $grant->expires_at?->toDateTimeString() ?? '';
                $lots[$expiry] ??= [$grant->expires_at, 0, []];
                $lots[$expiry][1] -= $credits;
                $lots[$expiry][2][] = $grant->id;
            }

            $allocations = [];

            foreach ($lots as [$expiresAt, $credits, $grants]) {
                $lot = $to->grants()->create([
                    'amount' => $credits,
                    'remaining' => $credits,
                    'source' => 'transfer',
                    'reason' => $reason,
                    'expires_at' => $expiresAt,
                    'meta' => ['from_wallet' => $from->id, 'from_grants' => $grants],
                ]);

                $allocations[] = [$lot->id, $credits];
            }

            self::credit($to, $amount);
            self::record($to, TransactionType::Transfer, $amount, $reason, null, ['from_wallet' => $from->id] + $meta, allocations: $allocations, parent: $sent);

            return $sent;
        });
    }

    /**
     * Expire due lots, release stale holds and grant the current allowances; true when that changed anything.
     * Most calls find nothing to do and only read; the lock is taken when there is work.
     */
    public static function refresh(Wallet $wallet): bool
    {
        if (! self::behind($wallet)) {
            return false;
        }

        $recorded = self::$recorded;
        self::locked($wallet, fn () => null);

        return self::$recorded > $recorded;
    }

    /** Reload the wallet without a lock and say whether a catch-up would change it. */
    private static function behind(Wallet $wallet): bool
    {
        $fresh = $wallet->newQuery()->whereKey($wallet->getKey())->firstOrFail();
        $wallet->setRawAttributes($fresh->getAttributes(), true);

        foreach (Credits::allowancesFor($wallet) as $allowance) {
            if ($allowance->owed($wallet) > 0) {
                return true;
            }
        }

        if ($wallet->holds()->pending()->where('expires_at', '<=', now())->exists()) {
            return true;
        }

        // Due credits beyond what pending holds reserve expire; those within it are kept for the holds.
        return (int) $wallet->grants()->due()->sum('remaining') > $wallet->held;
    }

    /**
     * Run an operation on the wallets' rows, locked and caught up, and copy the result back onto the caller's
     * models. Events wait for the commit, so a listener never sees a balance the database rolled back.
     *
     * @template T
     *
     * @param  Wallet|array<int, Wallet>  $wallets
     * @param  Closure(Wallet...): T  $operation  called with the locked wallets, in the order given
     * @return T
     */
    private static function locked(Wallet|array $wallets, Closure $operation): mixed
    {
        $wallets = is_array($wallets) ? $wallets : [$wallets];

        try {
            $result = DB::connection($wallets[0]->getConnectionName())->transaction(function () use ($wallets, $operation) {
                $fresh = [];

                // Lock in id order, so two operations on the same pair of wallets (transfers both ways) cannot deadlock.
                foreach (collect($wallets)->sortBy(fn (Wallet $wallet) => $wallet->getKey()) as $i => $wallet) {
                    /** @var Wallet $locked */
                    $locked = $wallet->newQuery()->whereKey($wallet->getKey())->lockForUpdate()->firstOrFail();

                    if ($wallet->relationLoaded('holder')) {
                        $locked->setRelation('holder', $wallet->getRelation('holder'));
                    }

                    $fresh[$i] = $locked;
                }

                ksort($fresh);
                $before = [];

                foreach ($fresh as $i => $wallet) {
                    $before[$i] = [$wallet->balance - $wallet->held, $wallet->held];
                    self::catchUp($wallet);
                }

                $result = $operation(...$fresh);

                foreach ($fresh as $i => $wallet) {
                    // What a settle or release gave back of a lot that expired under its hold expires now, not on
                    // the next use.
                    if ($wallet->held < $before[$i][1]) {
                        self::expireDue($wallet);
                    }

                    if ($wallet->isDirty()) {
                        $wallet->save();
                    }

                    self::checkLow($wallet, $before[$i][0]);
                    $wallets[$i]->setRawAttributes($wallet->getAttributes(), true);
                }

                return $result;
            });
        } catch (Throwable $e) {
            self::$events = [];

            throw $e;
        }

        self::flush();

        return $result;
    }

    private static function catchUp(Wallet $wallet): void
    {
        foreach (Credits::allowancesFor($wallet) as $allowance) {
            $allowance->grantInto($wallet);
        }

        $wallet->holds()
            ->pending()
            ->where('expires_at', '<=', now())
            ->orderBy('id')
            ->get()
            ->each(fn (Hold $hold) => self::releaseHold($wallet, $hold, 'Hold expired', HoldStatus::Expired));

        self::expireDue($wallet);
    }

    /**
     * Expire the remaining credits of due lots, except what pending holds reserve: they reserve the soonest-
     * expiring credits, which due lots are, so those stay until the hold is settled or released.
     */
    private static function expireDue(Wallet $wallet): void
    {
        $due = $wallet->grants()->due()->orderBy('expires_at')->orderBy('id')->get();

        if ($due->isEmpty()) {
            return;
        }

        $keep = $wallet->held;
        $allocations = [];
        $total = 0;

        foreach ($due as $grant) {
            $kept = min($keep, $grant->remaining);
            $keep -= $kept;

            if (($expire = $grant->remaining - $kept) > 0) {
                $grant->decrement('remaining', $expire);
                $allocations[] = [$grant->id, -$expire];
                $total += $expire;
            }
        }

        if ($total === 0) {
            return;
        }

        $wallet->balance -= $total;

        self::record($wallet, TransactionType::Expire, -$total, 'Credits expired', null, [], allocations: $allocations);
    }

    /** @internal Add a lot on a wallet the caller has locked and caught up. */
    public static function addLot(Wallet $wallet, TransactionType $type, int $amount, ?string $reason, ?CarbonInterface $expiresAt, string $source, ?string $key, array $meta): Transaction
    {
        $grant = $wallet->grants()->create([
            'amount' => $amount,
            'remaining' => $amount,
            'source' => $source,
            'reason' => $reason,
            'expires_at' => $expiresAt,
            'meta' => $meta ?: null,
        ]);

        self::credit($wallet, $amount);

        return self::record($wallet, $type, $amount, $reason, $key, $meta, allocations: [[$grant->id, $amount]]);
    }

    /** Add to the balance, refusing what a 64-bit column (and PHP's int) cannot hold: past the limit the number would wrap. */
    private static function credit(Wallet $wallet, int $amount): void
    {
        if ($amount > PHP_INT_MAX - $wallet->balance) {
            throw InvalidAmount::overflow($wallet, $amount);
        }

        $wallet->balance += $amount;
    }

    /**
     * Take credits from the lots in spend order, after the first `$skip` (what pending holds reserve, unless
     * given); the caller has checked that the wallet has them.
     */
    private static function take(Wallet $wallet, TransactionType $type, int $amount, ?string $reason, ?string $key, array $meta, int $heldChange = 0, ?Hold $hold = null, ?int $skip = null): Transaction
    {
        $allocations = self::drain($wallet, $amount, $skip ?? $wallet->held);
        $wallet->balance -= $amount;

        return self::record($wallet, $type, -$amount, $reason, $key, $meta, heldChange: $heldChange, hold: $hold, allocations: $allocations);
    }

    /** @return array<int, array{0: int, 1: int, 2: Grant}> grant id, credits taken (negative), the lot */
    private static function drain(Wallet $wallet, int $amount, int $skip): array
    {
        $left = $amount;
        $allocations = [];

        if ($left <= 0) {
            return [];
        }

        foreach ($wallet->grants()->spendable()->get() as $grant) {
            $reserved = min($skip, $grant->remaining);
            $skip -= $reserved;

            if (($free = $grant->remaining - $reserved) === 0) {
                continue;
            }

            $taken = min($left, $free);
            $grant->decrement('remaining', $taken);
            $allocations[] = [$grant->id, -$taken, $grant];

            if (($left -= $taken) === 0) {
                break;
            }
        }

        return $allocations;
    }

    private static function releaseHold(Wallet $wallet, Hold $hold, ?string $reason, HoldStatus $status = HoldStatus::Released): Transaction
    {
        $hold->forceFill(['status' => $status, 'captured' => 0])->save();
        $wallet->held -= $hold->amount;

        return self::record($wallet, TransactionType::Release, 0, $reason, null, [], heldChange: -$hold->amount, hold: $hold);
    }

    /** @param  array<int, array{0: int, 1: int}>  $allocations  grant id => amount */
    private static function record(Wallet $wallet, TransactionType $type, int $amount, ?string $reason, ?string $key, array $meta, int $heldChange = 0, ?Hold $hold = null, array $allocations = [], ?Transaction $parent = null): Transaction
    {
        $wallet->save();

        $transaction = $wallet->transactions()->create([
            'type' => $type,
            'amount' => $amount,
            'held_change' => $heldChange,
            'balance_after' => $wallet->balance,
            'held_after' => $wallet->held,
            'reason' => $reason,
            'idempotency_key' => $key,
            'parent_id' => $parent?->id,
            'hold_id' => $hold?->id,
            'meta' => $meta ?: null,
            'created_at' => now(),
        ]);

        $transaction->allocations()->createMany(array_map(
            fn (array $allocation) => ['grant_id' => $allocation[0], 'amount' => $allocation[1]],
            $allocations,
        ));

        self::$recorded++;
        self::later(new TransactionRecorded($wallet, $transaction));

        return $transaction;
    }

    /** The earlier result for a repeated key, provided the request is the same one; a different request under the key is a bug in the caller. */
    private static function replay(Wallet $wallet, ?string $key, TransactionType $type, Closure $matches): ?Transaction
    {
        if ($key === null) {
            return null;
        }

        $existing = $wallet->transactions()->where('idempotency_key', $key)->first();

        if ($existing === null) {
            return null;
        }

        if ($existing->type !== $type || ! $matches($existing)) {
            throw new IdempotencyConflict($key, $existing);
        }

        return $existing;
    }

    private static function pending(Hold $hold): Hold
    {
        $fresh = Hold::query()->whereKey($hold->getKey())->firstOrFail();

        if (! $fresh->isPending()) {
            throw new HoldNotPending($fresh);
        }

        return $fresh;
    }

    private static function checkLow(Wallet $wallet, int $before): void
    {
        $threshold = $wallet->low_balance_threshold ?? config('packstub-credits.low_balance_threshold');

        if ($threshold === null || $threshold === '') {
            return;
        }

        $after = $wallet->balance - $wallet->held;

        if ($before >= (int) $threshold && $after < (int) $threshold) {
            self::later(new BalanceLow($wallet, $after, (int) $threshold));
        }
    }

    private static function positive(int $amount): void
    {
        if ($amount <= 0) {
            throw InvalidAmount::notPositive($amount);
        }
    }

    private static function ttl(?int $ttl): int
    {
        $ttl ??= (int) config('packstub-credits.hold_ttl', 900);

        // Up to a century: past that the expiry no longer fits a timestamp.
        if ($ttl <= 0 || $ttl > 100 * 365 * 86_400) {
            throw InvalidAmount::lifetime($ttl);
        }

        return $ttl;
    }

    /** The string columns hold 255 characters; a longer value would fail in the database after the lock, or be cut short on a lenient one. */
    private static function text(?string $key, ?string $reason, ?string $source = null): void
    {
        foreach (['idempotency key' => $key, 'reason' => $reason, 'source' => $source] as $field => $value) {
            if ($value !== null && strlen($value) > 255) {
                throw InvalidAmount::textTooLong($field, $value);
            }
        }
    }

    /** How many lines this process has written, so refresh() can say whether it changed anything. */
    private static int $recorded = 0;

    /** @var array<int, object> */
    private static array $events = [];

    private static function later(object $event): void
    {
        self::$events[] = $event;
    }

    /** Dispatch what the operation recorded; inside an app's own transaction the events still wait for its commit. */
    private static function flush(): void
    {
        [$events, self::$events] = [self::$events, []];

        foreach ($events as $event) {
            event($event);
        }
    }
}
