<?php

namespace Packstub\Credits\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
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
 */
class Transaction extends Model
{
    public const UPDATED_AT = null;

    protected $table = 'credit_transactions';

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'type' => TransactionType::class,
            'amount' => 'integer',
            'held_change' => 'integer',
            'balance_after' => 'integer',
            'held_after' => 'integer',
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

    /** The spend or capture a refund gives back. */
    public function parent(): BelongsTo
    {
        return $this->belongsTo(self::class, 'parent_id');
    }

    public function refunds(): HasMany
    {
        return $this->hasMany(self::class, 'parent_id');
    }

    public function allocations(): HasMany
    {
        return $this->hasMany(Allocation::class);
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
