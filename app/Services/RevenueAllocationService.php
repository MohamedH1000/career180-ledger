<?php

namespace App\Services;

use App\Models\Payment;
use App\Models\RevenueAllocation;
use Illuminate\Support\Facades\DB;

/**
 * Turns a single subscription payment into one RevenueAllocation row per instructor
 * whose courses the subscription grants access to.
 *
 * Allocation basis (deliberately simple, see /docs/ARCHITECTURE.md): the platform's cut
 * comes off the top, and what's left is split *equally* across the distinct instructors
 * enrolled under this subscription — not weighted by engagement/watch-time, which would
 * need a tracking subsystem this exercise doesn't build. Cents that don't divide evenly
 * are handed out one-by-one, in ascending instructor_id order, to the first N
 * instructors — deterministic and reproducible, and the sum always equals the pool
 * exactly (no cent is ever lost or invented).
 */
class RevenueAllocationService
{
    public function allocate(Payment $payment): void
    {
        DB::transaction(function () use ($payment) {
            // Idempotent: if this payment has already been allocated (e.g. this method
            // was called twice by a retried "record payment" job), do nothing.
            if (RevenueAllocation::query()->where('payment_id', $payment->id)->exists()) {
                return;
            }

            $subscription = $payment->subscription()->lockForUpdate()->first();

            $instructorIds = $subscription->instructors()
                ->orderBy('instructors.id')
                ->pluck('instructors.id')
                ->unique()
                ->values();

            if ($instructorIds->isEmpty()) {
                // No instructors to pay (e.g. an empty/placeholder subscription) — the
                // whole payment is the platform's. Nothing to allocate.
                return;
            }

            $plan = $subscription->plan;
            $platformFeeCents = intdiv($payment->amount_cents * $plan->platform_fee_bps, 10_000);
            $instructorPoolCents = $payment->amount_cents - $platformFeeCents;

            $count = $instructorIds->count();
            $baseShare = intdiv($instructorPoolCents, $count);
            $remainder = $instructorPoolCents % $count;

            foreach ($instructorIds as $index => $instructorId) {
                $share = $baseShare + ($index < $remainder ? 1 : 0);

                RevenueAllocation::query()->create([
                    'subscription_id' => $subscription->id,
                    'instructor_id' => $instructorId,
                    'payment_id' => $payment->id,
                    'gross_share_cents' => $share,
                ]);
            }
        });
    }
}
