<?php

namespace Packstub\Credits\Exceptions;

use LogicException;
use Packstub\Credits\Models\Hold;

class HoldNotPending extends LogicException
{
    public function __construct(public readonly Hold $hold)
    {
        parent::__construct("Hold {$hold->id} is {$hold->status->value}, not pending.");
    }
}
