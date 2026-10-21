<?php

namespace Packstub\Credits\Events;

use Illuminate\Contracts\Events\ShouldDispatchAfterCommit;
use Illuminate\Foundation\Events\Dispatchable;
use Packstub\Credits\Models\Transaction;
use Packstub\Credits\Models\Wallet;

/** Every line of the ledger as it is written: a grant, spend, hold, capture, release, expiry, refund or adjustment. */
class TransactionRecorded implements ShouldDispatchAfterCommit
{
    use Dispatchable;

    public function __construct(
        public readonly Wallet $wallet,
        public readonly Transaction $transaction,
    ) {}
}
