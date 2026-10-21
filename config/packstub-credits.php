<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Meters
    |--------------------------------------------------------------------------
    |
    | What a unit of usage costs in credits: `credits` per `per` units. A meter
    | charges whole credits and carries the fraction on the wallet, so 400
    | tokens at 1 credit per 1,000 charge nothing now and the third call of
    | that size charges 1: nothing is rounded away, and nothing is rounded up.
    |
    */

    'meters' => [
        // 'ai.input_tokens' => ['credits' => 1, 'per' => 1_000],
        // 'ai.output_tokens' => ['credits' => 5, 'per' => 1_000],
    ],

    /*
    |--------------------------------------------------------------------------
    | Holds
    |--------------------------------------------------------------------------
    |
    | How long a hold reserves credits when it is placed without a lifetime,
    | in seconds. A hold nobody settles or releases (a worker that died
    | mid-call) is released when it expires, so the credits come back.
    |
    */

    'hold_ttl' => (int) env('CREDITS_HOLD_TTL', 900),

    /*
    |--------------------------------------------------------------------------
    | Refunds
    |--------------------------------------------------------------------------
    |
    | A refund goes back to the lots the credits came from, keeping their
    | expiry. Credits whose lot expired in the meantime come back as a new lot
    | that lives this many days, so a refund is never worthless on arrival.
    |
    */

    'refund_grace_days' => (int) env('CREDITS_REFUND_GRACE_DAYS', 7),

    /*
    |--------------------------------------------------------------------------
    | Low balance
    |--------------------------------------------------------------------------
    |
    | BalanceLow is dispatched when a wallet's available credits fall below
    | this number (a wallet's own `low_balance_threshold` wins). Null turns
    | the event off.
    |
    */

    'low_balance_threshold' => env('CREDITS_LOW_BALANCE_THRESHOLD'),

];
