<?php

namespace App\Enums;

enum PayoutItemStatus: string
{
    /** Claimed (money reserved) but the provider hasn't been called yet. */
    case Pending = 'pending';
    /** Sent to the provider; outcome not yet confirmed. */
    case Processing = 'processing';
    case Succeeded = 'succeeded';
    case Failed = 'failed';
}
