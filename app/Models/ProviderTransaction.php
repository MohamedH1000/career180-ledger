<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ProviderTransaction extends Model
{
    use HasFactory;

    protected $fillable = [
        'payout_item_id',
        'idempotency_key',
        'attempt',
        'outcome',
        'provider_reference',
        'raw_payload',
    ];

    protected function casts(): array
    {
        return [
            'attempt' => 'integer',
            'raw_payload' => 'array',
        ];
    }

    public function payoutItem(): BelongsTo
    {
        return $this->belongsTo(PayoutItem::class);
    }
}
