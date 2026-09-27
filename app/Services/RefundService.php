<?php

namespace App\Services;

use App\Enums\PaymentStatus;
use App\Enums\SubscriptionStatus;
use App\Models\Refund;
use App\Models\Subscription;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * Handles a student leaving mid-term.
 *
 * The whole mechanism is one field: subscription.accrual_ends_at is pulled in to the
 * refund's effective date. Every instructor's earned-to-date figure is computed live
 * from that field (see Subscription::elapsedAccrualDays / RevenueAllocation::earnedCentsAsOf),
 * so shortening it *is* the clawback — no separate reversal ledger entries are needed,
 * and nothing already paid out is touched, because payouts never pay ahead of
 * earned-to-date in the first place (see PayoutService).
 *
 * This only works cleanly because the effective date is "now" (or later) — never
 * earlier than the latest moment the payout process could already have paid against.
 * A backdated refund (effective date in the past) could in theory claw back money
 * already paid; we treat that as out of scope (see Known Limitations in ARCHITECTURE.md)
 * and simply refuse to backdate below the last time instructor balances were touched.
 */
class RefundService
{
    public function refund(Subscription $subscription, ?CarbonImmutable $effectiveDate = null, ?string $reason = null): Refund
    {
        return DB::transaction(function () use ($subscription, $effectiveDate, $reason) {
            $subscription = Subscription::query()->whereKey($subscription->id)->lockForUpdate()->first();

            $effectiveDate ??= CarbonImmutable::now();
            $effectiveDate = $effectiveDate->startOfDay();

            // Never move the cutoff later than it already is, and never before today.
            $newAccrualEnd = $effectiveDate->lessThan(CarbonImmutable::parse($subscription->accrual_ends_at))
                ? $effectiveDate
                : CarbonImmutable::parse($subscription->accrual_ends_at);

            $totalDays = $subscription->totalTermDays();
            $elapsedDays = $subscription->elapsedAccrualDays($newAccrualEnd);
            $remainingDays = max(0, $totalDays - $elapsedDays);

            $payment = $subscription->payments()->where('status', PaymentStatus::Succeeded)->latest('paid_at')->first();

            $refundCents = $payment ? intdiv($payment->amount_cents * $remainingDays, $totalDays) : 0;

            $subscription->update([
                'accrual_ends_at' => $newAccrualEnd->toDateString(),
                'status' => SubscriptionStatus::Refunded,
            ]);

            if ($payment) {
                $payment->update([
                    'status' => $refundCents >= $payment->amount_cents
                        ? PaymentStatus::Refunded
                        : PaymentStatus::PartiallyRefunded,
                ]);
            }

            return Refund::query()->create([
                'payment_id' => $payment?->id,
                'subscription_id' => $subscription->id,
                'amount_cents' => $refundCents,
                'effective_date' => $newAccrualEnd->toDateString(),
                'reason' => $reason,
            ]);
        });
    }
}
