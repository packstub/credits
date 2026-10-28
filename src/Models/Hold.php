<?php

namespace Packstub\Credits\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Support\Carbon;
use Packstub\Credits\Enums\HoldStatus;
use Packstub\Credits\Enums\TransactionType;
use Packstub\Credits\Support\Ledger;

/**
 * Credits reserved for work whose cost is known only afterwards, an AI turn
 * typically: placed before the call so two calls cannot spend the same
 * credits, settled with what the call cost, or released when it never ran.
 *
 * @property int $id
 * @property int $wallet_id
 * @property int $amount
 * @property int|null $captured
 * @property int $shortfall
 * @property HoldStatus $status
 * @property string|null $reason
 * @property Carbon $expires_at
 * @property array|null $meta
 */
class Hold extends Model
{
    protected $table = 'credit_holds';

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'wallet_id' => 'integer',
            'amount' => 'integer',
            'captured' => 'integer',
            'shortfall' => 'integer',
            'status' => HoldStatus::class,
            'expires_at' => 'datetime',
            'meta' => 'array',
        ];
    }

    public function wallet(): BelongsTo
    {
        return $this->belongsTo(Wallet::class);
    }

    /** The ledger lines of this hold: the hold itself, then its capture or release. */
    public function transactions(): HasMany
    {
        return $this->hasMany(Transaction::class);
    }

    /** The capture that settled the hold, once it is settled. */
    public function settlement(): HasOne
    {
        return $this->hasOne(Transaction::class)->where('type', TransactionType::Capture);
    }

    public function scopePending(Builder $query): void
    {
        $query->where('status', HoldStatus::Pending);
    }

    public function isPending(): bool
    {
        return $this->status === HoldStatus::Pending;
    }

    public function isSettled(): bool
    {
        return $this->status === HoldStatus::Captured;
    }

    /**
     * Charge exactly this much, which may be more than was held as long as the wallet has the difference;
     * throws InsufficientCredits otherwise and the hold stays pending.
     */
    public function capture(int $amount): Transaction
    {
        return Ledger::capture($this, $amount, strict: true);
    }

    /**
     * Charge what the work cost, for work that already happened: whatever the wallet cannot cover beyond the
     * hold is recorded on the hold as a shortfall (and a Shortfall event) instead of failing.
     */
    public function settle(int $amount): Transaction
    {
        return Ledger::capture($this, $amount, strict: false);
    }

    /**
     * Settle with measured usage priced by the meters, e.g. ['ai.input_tokens' => 1200, 'ai.output_tokens' => 340].
     *
     * @param  array<string, int|float>  $usage
     */
    public function settleUsage(array $usage): Transaction
    {
        return Ledger::capture($this, null, strict: false, usage: $usage);
    }

    /** Give the reserved credits back without charging anything. */
    public function release(): Transaction
    {
        return Ledger::release($this);
    }

    /**
     * Keep the hold for another `$ttl` seconds from now (the configured `hold_ttl` by default), for work that
     * is still running: a long stream, a slow job. Never shortens it; throws HoldNotPending once it is over.
     */
    public function extend(?int $ttl = null): static
    {
        return Ledger::extend($this, $ttl);
    }
}
