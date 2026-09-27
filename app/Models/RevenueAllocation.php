<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class RevenueAllocation extends Model
{
    use HasFactory;

    protected $fillable = [
        'subscription_id',
        'instructor_id',
        'payment_id',
        'gross_share_cents',
    ];

    protected function casts(): array
    {
        return [
            'gross_share_cents' => 'integer',
        ];
    }

    public function subscription(): BelongsTo
    {
        return $this->belongsTo(Subscription::class);
    }

    public function instructor(): BelongsTo
    {
        return $this->belongsTo(Instructor::class);
    }

    public function payment(): BelongsTo
    {
        return $this->belongsTo(Payment::class);
    }

    /**
     * This allocation's share of the term that has actually been earned as of $asOf,
     * computed directly from the fraction of elapsed days — never accumulated day by day.
     * Using floor(gross * elapsed / total) rather than an incremental daily sum guarantees
     * the result is exactly gross_share_cents once elapsed == total, with no rounding
     * drift, and is monotonically non-decreasing while accrual_ends_at is unchanged.
     */
    public function earnedCentsAsOf(?\DateTimeInterface $asOf = null): int
    {
        $subscription = $this->relationLoaded('subscription') ? $this->subscription : $this->subscription()->first();

        $elapsed = $subscription->elapsedAccrualDays($asOf);
        $total = $subscription->totalTermDays();

        return intdiv($this->gross_share_cents * $elapsed, $total);
    }
}
