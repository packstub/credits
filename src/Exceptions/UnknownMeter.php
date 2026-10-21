<?php

namespace Packstub\Credits\Exceptions;

use InvalidArgumentException;

class UnknownMeter extends InvalidArgumentException
{
    public function __construct(public readonly string $meter)
    {
        parent::__construct("No rate for the meter [{$meter}]; add it to packstub-credits.meters.");
    }
}
