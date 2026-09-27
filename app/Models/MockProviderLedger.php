<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class MockProviderLedger extends Model
{
    protected $table = 'mock_provider_ledger';

    protected $fillable = [
        'idempotency_key',
        'actual_outcome',
        'provider_reference',
        'failure_reason',
    ];
}
