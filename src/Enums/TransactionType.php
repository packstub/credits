<?php

namespace Packstub\Credits\Enums;

enum TransactionType: string
{
    case Grant = 'grant';
    case Spend = 'spend';
    case Hold = 'hold';
    case Capture = 'capture';
    case Release = 'release';
    case Expire = 'expire';
    case Refund = 'refund';
    case Adjust = 'adjust';

    // Credits moved between two wallets: negative on the sender's ledger, positive on the receiver's.
    case Transfer = 'transfer';

    /** A transaction a refund may point back to: it took credits for something. */
    public function isRefundable(): bool
    {
        return $this === self::Spend || $this === self::Capture;
    }
}
