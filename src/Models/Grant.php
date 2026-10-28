<?php

namespace Packstub\Credits\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
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
            'wallet_id' => 'integer',
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

    public function allocations(): HasMany
    {
        return $this->hasMany(Allocation::class);
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

    /** Lots with credits left that have not expired. */
    public function scopeActive(Builder $query): void
    {
        $query->where('remaining', '>', 0)
            ->where(fn (Builder $query) => $query->whereNull('expires_at')->orWhere('expires_at', '>', now()));
    }

    /** Lots past their expiry that still have credits: due to expire, or kept for a pending hold. */
    public function scopeDue(Builder $query): void
    {
        $query->where('remaining', '>', 0)
            ->whereNotNull('expires_at')
            ->where('expires_at', '<=', now());
    }
}
