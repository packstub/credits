<?php

namespace Packstub\Credits\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * A lot: credits that arrived together, with one source and one expiry.
 * Spends take from the lots that expire soonest, then from those that never
 * do, so a customer never loses credits that could have been spent.
 *
 * @property int $id
 * @property int $wallet_id
 * @property int $amount
 * @property int $remaining
 * @property string $source
 * @property string|null $reason
 * @property Carbon|null $expires_at
 * @property array|null $meta
 */
class Grant extends Model
{
    protected $table = 'credit_grants';

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'amount' => 'integer',
            'remaining' => 'integer',
            'expires_at' => 'datetime',
            'meta' => 'array',
        ];
    }

    public function wallet(): BelongsTo
    {
        return $this->belongsTo(Wallet::class);
    }

    public function isExpired(): bool
    {
        return $this->expires_at !== null && $this->expires_at->lte(now());
    }

    /** Lots with credits left, in the order spends take from them: soonest expiry first, then never-expiring, oldest first. */
    public function scopeSpendable(Builder $query): void
    {
        $query->where('remaining', '>', 0)
            ->orderByRaw('case when expires_at is null then 1 else 0 end')
            ->orderBy('expires_at')
            ->orderBy('id');
    }
}
