<?php

namespace Packstub\Credits\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use LogicException;

/**
 * How much of one lot one transaction moved: negative took from it, positive
 * put into it. A spend across three lots has three; the sum of a
 * transaction's allocations is its amount. Never updated, like the
 * transaction it belongs to.
 *
 * @property int $id
 * @property int $transaction_id
 * @property int $grant_id
 * @property int $amount
 */
class Allocation extends Model
{
    public $timestamps = false;

    protected $table = 'credit_allocations';

    protected $guarded = [];

    protected static function booted(): void
    {
        static::updating(fn () => throw new LogicException('Credit allocations are append-only.'));
        static::deleting(fn () => throw new LogicException('Credit allocations are append-only.'));
    }

    protected function casts(): array
    {
        return [
            'transaction_id' => 'integer',
            'grant_id' => 'integer',
            'amount' => 'integer',
        ];
    }

    public function transaction(): BelongsTo
    {
        return $this->belongsTo(Transaction::class);
    }

    public function grant(): BelongsTo
    {
        return $this->belongsTo(Grant::class);
    }
}
