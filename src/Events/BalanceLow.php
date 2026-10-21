<?php

namespace Packstub\Credits\Events;

use Illuminate\Contracts\Events\ShouldDispatchAfterCommit;
use Illuminate\Foundation\Events\Dispatchable;
use Packstub\Credits\Models\Wallet;

/** The wallet's available credits just fell below the threshold: once per crossing, not on every spend below it. */
class BalanceLow implements ShouldDispatchAfterCommit
{
    use Dispatchable;

    public function __construct(
        public readonly Wallet $wallet,
        public readonly int $available,
        public readonly int $threshold,
    ) {}
}
