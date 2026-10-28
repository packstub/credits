<?php

namespace Packstub\Credits;

use Closure;
use Illuminate\Database\Eloquent\Model;
use InvalidArgumentException;
use LogicException;
use Packstub\Credits\Enums\HoldStatus;
use Packstub\Credits\Models\Wallet;
use Packstub\Credits\Support\Allowance;
use Packstub\Credits\Support\Ledger;
use Throwable;

/** What the app told the package: the allowances each holder's plan grants and the meter rates it pays. */
class CreditsManager
{
    /** @var (Closure(Model, string): iterable<Allowance>)|null */
    protected ?Closure $allowances = null;

    /** @var (Closure(Model, string): array<string, array{credits: int, per?: int}>)|null */
    protected ?Closure $meters = null;

    public function wallet(Model $holder, string $name = 'default'): Wallet
    {
        if (! $holder->exists || $holder->getKey() === null) {
            throw new LogicException('Save the '.class_basename($holder).' before giving it credits: a wallet belongs to a stored model.');
        }

        if ($name === '' || strlen($name) > 255) {
            throw new InvalidArgumentException('A wallet name is 1 to 255 characters.');
        }

        $wallet = Wallet::query()->firstOrCreate([
            'holder_type' => $holder->getMorphClass(),
            'holder_id' => $holder->getKey(),
            'name' => $name,
        ]);

        return $wallet->setRelation('holder', $holder);
    }

    /**
     * The allowances a holder's wallet gets, asked on every operation: fn (Model $holder, string $wallet) => [Allowance::monthly(1_000)].
     * Return what the plan grants right now: a bigger allowance mid-period grants the difference at once, a smaller one takes effect next period.
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

        $allowances = [...(($this->allowances)($holder, $wallet->name) ?? [])];
        $names = array_map(fn (Allowance $allowance) => $allowance->name(), $allowances);

        // Each period's lot is keyed by the allowance's name, so a second one under the same name would never be granted.
        if (count($names) !== count(array_unique($names))) {
            throw new InvalidArgumentException('Two allowances of the wallet ['.$wallet->name.'] share a name; tell them apart with ->named().');
        }

        return $allowances;
    }

    /**
     * Meter rates per holder, over the configured ones: fn (Model $holder, string $wallet) => ['ai.output_tokens' => ['credits' => 4, 'per' => 1_000]].
     * A meter the resolver leaves out keeps its configured rate.
     *
     * @param  (Closure(Model, string): array<string, array{credits: int, per?: int}>)|null  $resolver
     */
    public function metersUsing(?Closure $resolver): void
    {
        $this->meters = $resolver;
    }

    /** @return array<string, mixed> meter => ['credits' => …, 'per' => …] */
    public function metersFor(Wallet $wallet): array
    {
        $configured = config('packstub-credits.meters', []);

        if ($this->meters === null || ($holder = $wallet->holder) === null) {
            return $configured;
        }

        return [...$configured, ...(($this->meters)($holder, $wallet->name) ?? [])];
    }

    /**
     * Catch up every wallet with a due lot or a stale hold, for the events of wallets nobody is touching; returns
     * how many changed. A wallet that fails (an allowance resolver that throws) is reported and skipped.
     */
    public function expire(): int
    {
        $count = 0;

        Wallet::query()
            ->where(fn ($query) => $query
                ->whereHas('grants', fn ($grants) => $grants->due())
                ->orWhereHas('holds', fn ($holds) => $holds->where('status', HoldStatus::Pending)->where('expires_at', '<=', now())))
            ->with('holder')
            ->lazyById()
            ->each(function (Wallet $wallet) use (&$count) {
                try {
                    $count += (int) Ledger::refresh($wallet);
                } catch (Throwable $e) {
                    report($e);
                }
            });

        return $count;
    }
}
