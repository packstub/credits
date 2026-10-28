<?php

namespace Packstub\Credits\Exceptions;

use InvalidArgumentException;
use Packstub\Credits\Models\Transaction;
use Packstub\Credits\Models\Wallet;

class InvalidAmount extends InvalidArgumentException implements CreditsException
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

    public static function usage(string $meter, mixed $quantity): self
    {
        return new self(sprintf('Usage of the meter [%s] must be a number of zero or more units, %s given.', $meter, get_debug_type($quantity) === 'null' ? 'null' : var_export($quantity, true)));
    }

    public static function usageTooLarge(string $meter, int|float $quantity): self
    {
        return new self(sprintf('Usage of the meter [%s] is too large to price in credits: %s units.', $meter, var_export($quantity, true)));
    }

    public static function overflow(Wallet $wallet, int $amount): self
    {
        return new self("Adding {$amount} credits would take wallet {$wallet->id} past the largest balance the ledger can hold.");
    }

    public static function outOfRange(int $amount): self
    {
        return new self("An amount of {$amount} credits is outside the range the ledger can hold.");
    }

    public static function textTooLong(string $field, string $value): self
    {
        return new self(sprintf('The %s of a credit transaction holds at most 255 characters, %d given.', $field, strlen($value)));
    }

    public static function noUsage(): self
    {
        return new self('Metered usage needs at least one meter and its quantity.');
    }

    public static function lifetime(int $ttl): self
    {
        return new self("A hold lives a positive number of seconds, {$ttl} given.");
    }

    public static function sameWallet(): self
    {
        return new self('A transfer needs two different wallets.');
    }
}
