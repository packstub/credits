<?php

namespace Packstub\Credits\Models;

use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\MorphTo;
use Packstub\Credits\Support\Ledger;

/**
 * One balance of one holder (a user, a team, a tenant), named so a holder
 * can keep separate currencies ("default", "sms", "seats"). `balance` is
 * every unexpired credit; `held` is what pending holds reserve of it; what
 * can be spent is the difference, `available()`. Every change goes through
 * the ledger under a row lock: never update these columns yourself.
 *
 * @property int $id
 * @property string $name
 * @property int $balance
 * @property int $held
 * @property int|null $low_balance_threshold
 * @property array<string, int>|null $meter_remainders
 */
class Wallet extends Model
{
    protected $table = 'credit_wallets';

    protected $guarded = [];

    protected $attributes = [
        'balance' => 0,
        'held' => 0,
    ];

    protected function casts(): array
    {
        return [
            'balance' => 'integer',
            'held' => 'integer',
            'low_balance_threshold' => 'integer',
            'meter_remainders' => 'array',
        ];
    }

    public function holder(): MorphTo
    {
        return $this->morphTo();
    }

    public function grants(): HasMany
    {
        return $this->hasMany(Grant::class);
    }

    public function holds(): HasMany
    {
        return $this->hasMany(Hold::class);
    }

    public function transactions(): HasMany
    {
        return $this->hasMany(Transaction::class);
    }

    /** What can be spent now: unexpired credits less what holds reserve, after expiring lots and renewing allowances. */
    public function available(): int
    {
        Ledger::refresh($this);

        return $this->balance - $this->held;
    }

    /** Every unexpired credit, held ones included. */
    public function total(): int
    {
        Ledger::refresh($this);

        return $this->balance;
    }

    public function canSpend(int $amount): bool
    {
        return $amount <= $this->available();
    }

    /** Add a lot of credits, optionally expiring; `source` says where they came from ("top-up", "plan", "promo"). */
    public function grant(int $amount, ?string $reason = null, ?CarbonInterface $expiresAt = null, string $source = 'grant', ?string $key = null, array $meta = []): Transaction
    {
        return Ledger::grant($this, $amount, $reason, $expiresAt, $source, $key, $meta);
    }

    /** Take credits now, soonest-expiring lots first; throws InsufficientCredits when fewer are available. */
    public function spend(int $amount, ?string $reason = null, ?string $key = null, array $meta = []): Transaction
    {
        return Ledger::spend($this, $amount, $reason, $key, $meta);
    }

    /** Reserve credits for work whose cost is known afterwards; settle or release the hold when it is. */
    public function hold(int $amount, ?string $reason = null, ?int $ttl = null, ?string $key = null, array $meta = []): Hold
    {
        return Ledger::hold($this, $amount, $reason, $ttl, $key, $meta);
    }

    /**
     * Charge measured usage through the configured meters, carrying fractions of a credit to the next call.
     * The usage already happened, so a short balance is charged what it has and the rest recorded as a shortfall.
     *
     * @param  string|array<string, int|float>  $meter  a meter name, or meter => quantity
     */
    public function meter(string|array $meter, int|float|null $quantity = null, ?string $reason = null, ?string $key = null, array $meta = []): ?Transaction
    {
        return Ledger::meter($this, is_array($meter) ? $meter : [$meter => $quantity], $reason, $key, $meta);
    }

    /** Give back a spend or a capture, in full or in part, to the lots it came from. */
    public function refund(Transaction $transaction, ?int $amount = null, ?string $reason = null, ?string $key = null): Transaction
    {
        return Ledger::refund($this, $transaction, $amount, $reason, $key);
    }

    /** An operator's correction: positive adds a lot, negative takes credits like a spend. */
    public function adjust(int $amount, string $reason, ?string $key = null, array $meta = []): Transaction
    {
        return Ledger::adjust($this, $amount, $reason, $key, $meta);
    }
}
