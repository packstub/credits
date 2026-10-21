<?php

namespace Packstub\Credits;

use Closure;
use Illuminate\Database\Eloquent\Model;
use Packstub\Credits\Enums\HoldStatus;
use Packstub\Credits\Models\Wallet;
use Packstub\Credits\Support\Allowance;
use Packstub\Credits\Support\Ledger;

/** What the app told the package: the allowances each holder's plan grants. */
class CreditsManager
{
    /** @var (Closure(Model, string): iterable<Allowance>)|null */
    protected ?Closure $allowances = null;

    public function wallet(Model $holder, string $name = 'default'): Wallet
    {
        $wallet = Wallet::query()->firstOrCreate([
            'holder_type' => $holder->getMorphClass(),
            'holder_id' => $holder->getKey(),
            'name' => $name,
        ]);

        return $wallet->setRelation('holder', $holder);
    }

    /**
     * The allowances a holder's wallet gets, asked on every operation: fn (Model $holder, string $wallet) => [Allowance::monthly(1_000)].
     * Return what the plan grants right now; an upgrade mid-period takes effect from the next period.
     *
     * @param  (Closure(Model, string): iterable<Allowance>)|null  $resolver
     */
    public function allowancesUsing(?Closure $resolver): void
    {
        $this->allowances = $resolver;
    }

    /** @return array<int, Allowance> */
    public function allowancesFor(Wallet $wallet): array
    {
        if ($this->allowances === null || ($holder = $wallet->holder) === null) {
            return [];
        }

        return [...($this->allowances)($holder, $wallet->name)];
    }

    /** Catch up every wallet with a due lot or a stale hold, for the events of wallets nobody is touching; returns how many. */
    public function expire(): int
    {
        $count = 0;

        Wallet::query()
            ->where(fn ($query) => $query
                ->whereHas('grants', fn ($grants) => $grants->where('remaining', '>', 0)->whereNotNull('expires_at')->where('expires_at', '<=', now()))
                ->orWhereHas('holds', fn ($holds) => $holds->where('status', HoldStatus::Pending)->where('expires_at', '<=', now())))
            ->lazyById()
            ->each(function (Wallet $wallet) use (&$count) {
                Ledger::refresh($wallet);
                $count++;
            });

        return $count;
    }
}
