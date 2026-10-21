<?php

namespace Packstub\Credits\Enums;

enum HoldStatus: string
{
    case Pending = 'pending';
    case Captured = 'captured';
    case Released = 'released';

    // Released because nobody settled it in time; a late settle() still charges it.
    case Expired = 'expired';
}
