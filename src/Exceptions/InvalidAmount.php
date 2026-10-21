<?php

namespace Packstub\Credits\Exceptions;

use InvalidArgumentException;
use Packstub\Credits\Models\Transaction;

class InvalidAmount extends InvalidArgumentException
{
    public static function notPositive(int $amount): self
    {
        return new self("An amount of credits must be positive, {$amount} given.");
    }

    public static function negative(int $amount): self
    {
        return new self("A charge cannot be negative, {$amount} given.");
    }

    public static function notRefundable(Transaction $transaction): self
    {
        return new self("Transaction {$transaction->id} ({$transaction->type->value}) is not a spend or capture of this wallet.");
    }

    public static function overRefund(Transaction $transaction, int $amount, int $refundable): self
    {
        return new self("Cannot refund {$amount} of transaction {$transaction->id}: {$refundable} left to refund.");
    }
}
