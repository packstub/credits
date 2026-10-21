<?php

namespace Packstub\Credits\Support;

use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use InvalidArgumentException;
use Packstub\Credits\Enums\TransactionType;
use Packstub\Credits\Models\Wallet;

/**
 * Credits a plan gives every period: `Allowance::monthly(1_000)`. The
 * current period's lot is granted the first time the wallet is touched in
 * it, keyed by the period, so it arrives once however many workers race for
 * it and no scheduler has to run. Unless it rolls over, the lot expires when
 * the period ends. A wallet nobody touched for three months gets this
 * month's allowance, not three.
 */
final class Allowance
{
    private ?CarbonInterface $anchor = null;

    private bool $rollover = false;

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

    /** Periods start on this moment's anniversaries (a subscription's start) instead of the calendar's. */
    public function anchoredAt(?CarbonInterface $anchor): self
    {
        $this->anchor = $anchor;

        return $this;
    }

    /** Unused credits stay after the period ends instead of expiring with it. */
    public function rollover(bool $rollover = true): self
    {
        $this->rollover = $rollover;

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
        return "allowance:{$this->name}:".$start->utc()->format('Y-m-d\TH:i:s');
    }

    /** Called by the ledger under the wallet's lock. */
    public function grantInto(Wallet $wallet): void
    {
        [$start, $end] = $this->period();
        $key = $this->key($start);

        if ($wallet->transactions()->where('idempotency_key', $key)->exists()) {
            return;
        }

        Ledger::addLot(
            $wallet,
            TransactionType::Grant,
            $this->amount,
            "Allowance for {$start->toDateString()} to {$end->toDateString()}",
            $this->rollover ? null : $end,
            'allowance',
            $key,
            ['allowance' => $this->name, 'period_start' => $start->toIso8601String(), 'period_end' => $end->toIso8601String()],
        );
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
