<?php

namespace Packstub\Credits\Exceptions;

use LogicException;
use Packstub\Credits\Models\Transaction;

/** The key was already used for a different request: two operations share a key by mistake. */
class IdempotencyConflict extends LogicException
{
    public function __construct(
        public readonly string $key,
        public readonly Transaction $existing,
    ) {
        parent::__construct("The idempotency key [{$key}] was already used for a different {$existing->type->value} (transaction {$existing->id}).");
    }
}
