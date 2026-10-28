<?php

namespace Packstub\Credits\Models;

use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;
use LogicException;
use Packstub\Credits\Enums\TransactionType;

/**
 * One line of the ledger, never updated: `amount` is what it did to the
 * balance (negative takes), `held_change` what it did to the reserved
 * credits, and the two `_after` columns are the wallet right after it, so a
 * statement can be printed from the rows alone.
 *
 * @property int $id
 * @property int $wallet_id
 * @property TransactionType $type
 * @property int $amount
 * @property int $held_change
 * @property int $balance_after
 * @property int $held_after
 * @property string|null $reason
 * @property string|null $idempotency_key
 * @property int|null $parent_id
 * @property int|null $hold_id
 * @property array|null $meta
 * @property Carbon|null $created_at
 */
class Transaction extends Model
{
    public const UPDATED_AT = null;

    protected $table = 'credit_transactions';

    protected $guarded = [];

    protected static function booted(): void
    {
        static::updating(fn () => throw new LogicException('Credit transactions are append-only.'));
        static::deleting(fn () => throw new LogicException('Credit transactions are append-only.'));
    }

    protected function casts(): array
    {
        return [
            'wallet_id' => 'integer',
            'type' => TransactionType::class,
            'amount' => 'integer',
            'held_change' => 'integer',
            'balance_after' => 'integer',
            'held_after' => 'integer',
            'parent_id' => 'integer',
            'hold_id' => 'integer',
            'meta' => 'array',
        ];
    }

    public function wallet(): BelongsTo
    {
        return $this->belongsTo(Wallet::class);
    }

    public function hold(): BelongsTo
    {
        return $this->belongsTo(Hold::class);
    }

    /** The spend or capture a refund gives back, or the sender's line of a transfer. */
    public function parent(): BelongsTo
    {
        return $this->belongsTo(self::class, 'parent_id');
    }

    /** The refunds of a spend or a capture; the receiver's line of a transfer. */
    public function refunds(): HasMany
    {
        return $this->hasMany(self::class, 'parent_id')->where('type', TransactionType::Refund);
    }

    public function allocations(): HasMany
    {
        return $this->hasMany(Allocation::class);
    }

    public function scopeOfType(Builder $query, TransactionType ...$types): void
    {
        $query->whereIn('type', $types);
    }

    /** Lines written from `$from` (inclusive) to `$to` (exclusive); either end may be open. */
    public function scopeBetween(Builder $query, ?CarbonInterface $from, ?CarbonInterface $to = null): void
    {
        $query->when($from, fn (Builder $query) => $query->where('created_at', '>=', $from))
            ->when($to, fn (Builder $query) => $query->where('created_at', '<', $to));
    }

    /** What the wallet could spend right after this line. */
    public function availableAfter(): int
    {
        return $this->balance_after - $this->held_after;
    }

    /** What is left to refund of a spend or a capture. */
    public function refundable(): int
    {
        if (! $this->type->isRefundable()) {
            return 0;
        }

        return -$this->amount - (int) $this->refunds()->sum('amount');
    }
}
