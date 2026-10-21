<?php

namespace Packstub\Credits\Events;

use Illuminate\Contracts\Events\ShouldDispatchAfterCommit;
use Illuminate\Foundation\Events\Dispatchable;
use Packstub\Credits\Models\Hold;
use Packstub\Credits\Models\Wallet;

/**
 * Usage cost more than the wallet could cover (a settle over the hold, or a meter on an empty wallet): the
 * work happened, so it was charged what was there and this says how much went unpaid. Bill it, cap the
 * customer, or alert someone.
 */
class Shortfall implements ShouldDispatchAfterCommit
{
    use Dispatchable;

    public function __construct(
        public readonly Wallet $wallet,
        public readonly ?Hold $hold,
        public readonly int $amount,
    ) {}
}
