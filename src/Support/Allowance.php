<?php

namespace Packstub\Credits\Support;

use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use InvalidArgumentException;
use Packstub\Credits\Enums\TransactionType;
use Packstub\Credits\Models\Grant;
use Packstub\Credits\Models\Wallet;

/**
 * Credits a plan gives every period: `Allowance::monthly(1_000)`. The
 * current period's lot is granted the first time the wallet is touched in
 * it, keyed by the period, so it arrives once however many workers race for
 * it and no scheduler has to run. Unless it rolls over, the lot expires when
 * the period ends. A wallet nobody touched for three months gets this
 * month's allowance, not three. A plan that grows mid-period (an upgrade,
 * more seats) grants the difference at once; one that shrinks keeps what
 * this period already granted and takes effect from the next.
 */
final class Allowance
{
    private ?CarbonInterface $anchor = null;

    private bool|int $rollover = false;

    private function __construct(
        public readonly int $amount,
        public readonly string $unit,
        private string $name = 'plan',
    ) {
        if ($amount <= 0) {
            throw new InvalidArgumentException('An allowance grants a positive number of credits.');
        }
    }

    public static function daily(int $amount): self
    {
        return new self($amount, 'day');
    }

    public static function weekly(int $amount): self
    {
        return new self($amount, 'week');
    }

    public static function monthly(int $amount): self
    {
        return new self($amount, 'month');
    }

    public static function yearly(int $amount): self
    {
        return new self($amount, 'year');
    }

    /** Tells two allowances of one wallet apart ("plan", "addon:seats"); part of each period's key. */
    public function named(string $name): self
    {
        $this->name = $name;

        return $this;
    }

    public function name(): string
    {
        return $this->name;
    }

    /**
     * Periods start on this moment's anniversaries (a subscription's start) instead of the calendar's. Before
     * the anchor nothing is granted: the first period starts on it.
     */
    public function anchoredAt(?CarbonInterface $anchor): self
    {
        $this->anchor = $anchor;

        return $this;
    }

    /**
     * Unused credits stay after the period ends instead of expiring with it: `rollover()` keeps them for good,
     * `rollover(2)` for two more periods, `rollover(false)` lets them expire with the period (the default).
     */
    public function rollover(bool|int $periods = true): self
    {
        if (is_int($periods) && $periods < 0) {
            throw new InvalidArgumentException('An allowance rolls over for zero or more periods.');
        }

        $this->rollover = $periods === 0 ? false : $periods;

        return $this;
    }

    /** @return array{0: CarbonImmutable, 1: CarbonImmutable} */
    public function period(?CarbonInterface $at = null): array
    {
        $at = CarbonImmutable::instance($at ?? now());

        if ($this->anchor === null) {
            $start = $at->startOf($this->unit);

            return [$start, $this->add($start, 1)];
        }

        $anchor = CarbonImmutable::instance($this->anchor);

        if ($at->lt($anchor)) {
            return [$anchor, $this->add($anchor, 1)];
        }

        // Count whole periods from the anchor; month and year steps never overflow, so an anchor on the 31st
        // renews on the last day of shorter months.
        $n = (int) floor(match ($this->unit) {
            'day' => $anchor->diffInDays($at),
            'week' => $anchor->diffInWeeks($at),
            'month' => $anchor->diffInMonths($at),
            'year' => $anchor->diffInYears($at),
        });

        while ($this->add($anchor, $n)->gt($at)) {
            $n--;
        }

        while ($this->add($anchor, $n + 1)->lte($at)) {
            $n++;
        }

        return [$this->add($anchor, $n), $this->add($anchor, $n + 1)];
    }

    public function key(CarbonInterface $start): string
    {
        return "allowance:{$this->name}:".CarbonImmutable::instance($start)->utc()->format('Y-m-d\TH:i:s');
    }

    /** What this period still owes the wallet: the allowance less what this period already granted. */
    public function owed(Wallet $wallet): int
    {
        [$start] = $this->period();

        if ($start->gt(now())) {
            return 0;
        }

        return max(0, $this->amount - $this->granted($wallet, $start));
    }

    /** Called by the ledger under the wallet's lock; true when it granted something. */
    public function grantInto(Wallet $wallet): bool
    {
        [$start, $end] = $this->period();

        if ($start->gt(now()) || ($owed = $this->amount - ($granted = $this->granted($wallet, $start))) <= 0) {
            return false;
        }

        // The first grant of a period is keyed by the period; a top-up after an upgrade by the period and the new
        // total, which only ever grows, so each is granted once.
        $key = $granted === 0 ? $this->key($start) : $this->key($start).':'.$this->amount;

        Ledger::addLot(
            $wallet,
            TransactionType::Grant,
            $owed,
            "Allowance for {$start->toDateString()} to {$end->toDateString()}".($granted === 0 ? '' : ' (increase)'),
            $this->expiresAt($end),
            'allowance',
            $key,
            ['allowance' => $this->name, 'period_start' => $start->toIso8601String(), 'period_end' => $end->toIso8601String()],
        );

        return true;
    }

    /** What this allowance already granted the wallet in the period starting at `$start`. */
    private function granted(Wallet $wallet, CarbonImmutable $start): int
    {
        // A coarse filter on the timestamp (a day of margin for time zones), then the exact period from the meta.
        return (int) $wallet->grants()
            ->where('source', 'allowance')
            ->where('created_at', '>=', $start->subDay())
            ->get(['amount', 'meta'])
            ->filter(fn (Grant $grant) => ($grant->meta['allowance'] ?? null) === $this->name
                && isset($grant->meta['period_start'])
                && CarbonImmutable::parse($grant->meta['period_start'])->eq($start))
            ->sum('amount');
    }

    private function expiresAt(CarbonImmutable $end): ?CarbonImmutable
    {
        if ($this->rollover === true) {
            return null;
        }

        for ($i = 0; $i < (int) $this->rollover; $i++) {
            [, $end] = $this->period($end);
        }

        return $end;
    }

    private function add(CarbonImmutable $from, int $n): CarbonImmutable
    {
        return match ($this->unit) {
            'day' => $from->addDays($n),
            'week' => $from->addWeeks($n),
            'month' => $from->addMonthsNoOverflow($n),
            'year' => $from->addYearsNoOverflow($n),
        };
    }
}
