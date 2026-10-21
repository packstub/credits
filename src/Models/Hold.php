<?php

namespace Packstub\Credits\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;
use Packstub\Credits\Enums\HoldStatus;
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

    public function isPending(): bool
    {
        return $this->status === HoldStatus::Pending;
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
}
