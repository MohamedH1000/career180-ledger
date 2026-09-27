<?php

namespace App\Models;

use App\Enums\SubscriptionStatus;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Subscription extends Model
{
    use HasFactory;

    protected $fillable = [
        'student_id',
        'plan_id',
        'status',
        'starts_at',
        'term_ends_at',
        'accrual_ends_at',
        'cancelled_at',
    ];

    protected function casts(): array
    {
        return [
            'status' => SubscriptionStatus::class,
            'starts_at' => 'date',
            'term_ends_at' => 'date',
            'accrual_ends_at' => 'date',
            'cancelled_at' => 'datetime',
        ];
    }

    public function student(): BelongsTo
    {
        return $this->belongsTo(User::class, 'student_id');
    }

    public function plan(): BelongsTo
    {
        return $this->belongsTo(SubscriptionPlan::class, 'plan_id');
    }

    public function payments(): HasMany
    {
        return $this->hasMany(Payment::class);
    }

    public function refunds(): HasMany
    {
        return $this->hasMany(Refund::class);
    }

    public function revenueAllocations(): HasMany
    {
        return $this->hasMany(RevenueAllocation::class);
    }

    public function instructors(): BelongsToMany
    {
        return $this->belongsToMany(Instructor::class, 'subscription_courses')
            ->withPivot('course_id')
            ->distinct();
    }

    /** Total number of days in the full paid-for term (starts_at .. term_ends_at]. */
    public function totalTermDays(): int
    {
        return max(1, $this->starts_at->diffInDays($this->term_ends_at));
    }

    /**
     * How many of the term's days have actually accrued as of $asOf, capped by both the
     * (possibly shortened) accrual cutoff and the full term length. This is the single
     * piece of time-math every instructor's earned-to-date figure is built from.
     */
    public function elapsedAccrualDays(?\DateTimeInterface $asOf = null): int
    {
        $asOfDate = CarbonImmutable::parse($asOf ?? CarbonImmutable::now())->startOfDay();
        $accrualEndsAt = CarbonImmutable::parse($this->accrual_ends_at)->startOfDay();
        $startsAt = CarbonImmutable::parse($this->starts_at)->startOfDay();

        $cutoff = $asOfDate->lessThan($accrualEndsAt) ? $asOfDate : $accrualEndsAt;

        $elapsed = $startsAt->diffInDays($cutoff, absolute: false);

        return (int) max(0, min($elapsed, $this->totalTermDays()));
    }
}
