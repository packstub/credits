<?php

namespace Packstub\Credits\Exceptions;

use InvalidArgumentException;

class UnknownMeter extends InvalidArgumentException implements CreditsException
{
    public function __construct(public readonly string $meter, ?string $message = null)
    {
        parent::__construct($message ?? "No rate for the meter [{$meter}]; add it to packstub-credits.meters.");
    }

    public static function misconfigured(string $meter): self
    {
        return new self($meter, "The meter [{$meter}] needs a whole number of credits (zero or more) per a whole number of units (one or more).");
    }
}
