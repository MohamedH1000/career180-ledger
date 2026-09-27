<?php

namespace App\Models;

use App\Enums\PayoutItemStatus;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class PayoutItem extends Model
{
    use HasFactory;

    protected $fillable = [
        'payout_batch_id',
        'instructor_id',
        'amount_cents',
        'status',
        'idempotency_key',
        'provider_reference',
        'failure_reason',
        'status_check_attempts',
        'requires_manual_review',
    ];

    protected function casts(): array
    {
        return [
            'amount_cents' => 'integer',
            'status' => PayoutItemStatus::class,
            'status_check_attempts' => 'integer',
            'requires_manual_review' => 'boolean',
        ];
    }

    public function batch(): BelongsTo
    {
        return $this->belongsTo(PayoutBatch::class, 'payout_batch_id');
    }

    public function instructor(): BelongsTo
    {
        return $this->belongsTo(Instructor::class);
    }

    public function providerTransactions(): HasMany
    {
        return $this->hasMany(ProviderTransaction::class);
    }

    public function isUnresolved(): bool
    {
        return in_array($this->status, [PayoutItemStatus::Pending, PayoutItemStatus::Processing], true);
    }
}
