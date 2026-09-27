<?php

namespace App\Enums;

enum ProviderOutcome: string
{
    case Succeeded = 'succeeded';
    case Failed = 'failed';
    /** The call itself did not return a definitive answer; the true result must be looked up later via status(). */
    case Timeout = 'timeout';
}
