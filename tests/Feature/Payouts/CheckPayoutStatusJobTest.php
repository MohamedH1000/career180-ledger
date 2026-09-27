<?php

use App\Enums\PayoutItemStatus;
use App\Jobs\CheckPayoutStatusJob;
use App\Jobs\PayInstructorJob;
use App\Models\PayoutItem;
use App\Payments\MockPaymentProvider;
use App\Payments\PaymentProviderContract;
use App\Models\SubscriptionPlan;
use App\Payments\ProviderResult;

/** A provider that never has an answer for anyone, ever — the genuinely-stuck case. */
class NeverAnsweringProvider implements PaymentProviderContract
{
    public function payout(string $idempotencyKey, int $amountCents, string $payeeReference): ProviderResult
    {
        return ProviderResult::timeout();
    }

    public function status(string $idempotencyKey): ?ProviderResult
    {
        return null;
    }
}

test('an item that never resolves is flagged for manual review instead of being guessed at', function () {
    app()->instance(PaymentProviderContract::class, new NeverAnsweringProvider);

    $plan = SubscriptionPlan::factory()->monthly()->create(['term_days' => 30, 'price_cents' => 2000, 'platform_fee_bps' => 0]);
    [, , $instructors] = fundedSubscription($plan, instructorCount: 1, startedDaysAgo: 30);
    $instructor = $instructors->first();

    PayInstructorJob::dispatchSync($instructor->id);
    $item = PayoutItem::where('instructor_id', $instructor->id)->sole();
    expect($item->status)->toBe(PayoutItemStatus::Processing);

    // Fast-forward to just below the ceiling to keep the test quick, rather than
    // looping the job's own 30s/60s/120s.. backoff for real.
    $item->update(['status_check_attempts' => 5]);

    CheckPayoutStatusJob::dispatchSync($item->id);

    $item->refresh();

    // Never guessed at: still exactly what it was before, just flagged for a human.
    expect($item->status)->toBe(PayoutItemStatus::Processing);
    expect($item->requires_manual_review)->toBeTrue();
});

test('a resolved item is left alone if a stray reconciliation job runs late', function () {
    app()->instance(
        PaymentProviderContract::class,
        new MockPaymentProvider(fn () => ['outcome' => 'succeeded', 'reveal' => true]),
    );

    $plan = SubscriptionPlan::factory()->monthly()->create(['term_days' => 30, 'price_cents' => 2000, 'platform_fee_bps' => 0]);
    [, , $instructors] = fundedSubscription($plan, instructorCount: 1, startedDaysAgo: 30);
    $instructor = $instructors->first();

    PayInstructorJob::dispatchSync($instructor->id);
    $item = PayoutItem::where('instructor_id', $instructor->id)->sole();
    expect($item->status)->toBe(PayoutItemStatus::Succeeded);

    // A CheckPayoutStatusJob dispatched for an already-resolved item (e.g. it was queued
    // before the first attempt resolved things) must be a safe no-op.
    CheckPayoutStatusJob::dispatchSync($item->id);

    $item->refresh();
    expect($item->status)->toBe(PayoutItemStatus::Succeeded);
});
