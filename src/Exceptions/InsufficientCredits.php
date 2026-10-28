<?php

namespace Packstub\Credits\Exceptions;

use Packstub\Credits\Models\Wallet;
use RuntimeException;

class InsufficientCredits extends RuntimeException implements CreditsException
{
    public function __construct(
        public readonly Wallet $wallet,
        public readonly int $requested,
        public readonly int $available,
    ) {
        parent::__construct("Not enough credits: {$requested} requested, {$available} available.");
    }

    /** How many more credits the request needs. */
    public function missing(): int
    {
        return $this->requested - $this->available;
    }
}
