<?php

use App\Enums\PayoutItemStatus;
use App\Jobs\CheckPayoutStatusJob;
use App\Jobs\PayInstructorJob;
use App\Models\InstructorBalance;
use App\Models\PayoutItem;
use App\Models\SubscriptionPlan;
use App\Payments\MockPaymentProvider;
use App\Payments\PaymentProviderContract;
use App\Services\PayoutService;
use Illuminate\Support\Facades\Artisan;

function forceProviderOutcome(string $outcome, bool $reveal = true, ?string $reason = null): void
{
    app()->instance(
        PaymentProviderContract::class,
        new MockPaymentProvider(fn () => ['outcome' => $outcome, 'reveal' => $reveal, 'reason' => $reason]),
    );
}

// Requirement: "Running the payout process twice never double-pays."
test('running artisan payouts:run twice never pays an instructor twice', function () {
    forceProviderOutcome('succeeded');

    $plan = SubscriptionPlan::factory()->monthly()->create(['term_days' => 30, 'price_cents' => 6000, 'platform_fee_bps' => 0]);
    [, , $instructors] = fundedSubscription($plan, instructorCount: 1, startedDaysAgo: 30); // fully earned
    $instructor = $instructors->first();

    Artisan::call('payouts:run');
    Artisan::call('payouts:run');

    $balance = InstructorBalance::query()->where('instructor_id', $instructor->id)->first();

    expect($balance->paid_cents)->toBe(6000);
    expect($balance->pending_cents)->toBe(0);
    expect(PayoutItem::where('instructor_id', $instructor->id)->where('status', PayoutItemStatus::Succeeded)->count())->toBe(1);
});

// Requirement: "Retried jobs never double-pay."
test('re-dispatching PayInstructorJob after it already succeeded pays nothing further', function () {
    forceProviderOutcome('succeeded');

    $plan = SubscriptionPlan::factory()->monthly()->create(['term_days' => 30, 'price_cents' => 4000, 'platform_fee_bps' => 0]);
    [, , $instructors] = fundedSubscription($plan, instructorCount: 1, startedDaysAgo: 30);
    $instructor = $instructors->first();

    PayInstructorJob::dispatchSync($instructor->id);
    PayInstructorJob::dispatchSync($instructor->id); // simulates a queue retry/duplicate dispatch

    $balance = InstructorBalance::query()->where('instructor_id', $instructor->id)->first();
    expect($balance->paid_cents)->toBe(4000);
    expect(PayoutItem::where('instructor_id', $instructor->id)->count())->toBe(1);
});

test('a job retried between claim and settle resumes the same claim instead of reserving twice', function () {
    forceProviderOutcome('succeeded');

    $plan = SubscriptionPlan::factory()->monthly()->create(['term_days' => 30, 'price_cents' => 5000, 'platform_fee_bps' => 0]);
    [, , $instructors] = fundedSubscription($plan, instructorCount: 1, startedDaysAgo: 30);
    $instructor = $instructors->first();

    $payouts = app(PayoutService::class);

    // First "attempt" claims the money (simulates the worker reaching this point, then crashing).
    $firstAttemptItem = $payouts->claimOrResume($instructor);
    expect($firstAttemptItem)->not->toBeNull();

    // The retried job re-enters from the top and calls claimOrResume() again.
    $secondAttemptItem = $payouts->claimOrResume($instructor);

    expect($secondAttemptItem->id)->toBe($firstAttemptItem->id);

    $balance = InstructorBalance::query()->where('instructor_id', $instructor->id)->first();
    expect($balance->pending_cents)->toBe(5000); // reserved once, not twice
    expect(PayoutItem::where('instructor_id', $instructor->id)->count())->toBe(1);

    // The retry finishes the job by settling.
    $payouts->settle($secondAttemptItem);

    $balance->refresh();
    expect($balance->paid_cents)->toBe(5000);
    expect($balance->pending_cents)->toBe(0);
});

// Requirement: "Unreliable provider responses never cause duplicate payments."
//
// Note: with QUEUE_CONNECTION=sync (see phpunit.xml), the sync driver ignores ->delay()
// and runs a chained dispatch immediately — so PayInstructorJob's own
// CheckPayoutStatusJob::dispatch(...)->delay(...) would otherwise resolve the item
// within the very same dispatchSync() call. We fake just that job class so it's
// recorded instead of executed, letting us drive PayoutService::reconcile() ourselves
// to simulate it running "later".
test('a timeout followed by a delayed succeeded confirmation pays exactly once', function () {
    forceProviderOutcome('succeeded', reveal: false); // "timeout", but money did move
    \Illuminate\Support\Facades\Bus::fake([CheckPayoutStatusJob::class]);

    $plan = SubscriptionPlan::factory()->monthly()->create(['term_days' => 30, 'price_cents' => 7000, 'platform_fee_bps' => 0]);
    [, , $instructors] = fundedSubscription($plan, instructorCount: 1, startedDaysAgo: 30);
    $instructor = $instructors->first();

    PayInstructorJob::dispatchSync($instructor->id);

    $item = PayoutItem::where('instructor_id', $instructor->id)->sole();
    expect($item->status)->toBe(PayoutItemStatus::Processing);

    $balance = InstructorBalance::query()->where('instructor_id', $instructor->id)->first();
    expect($balance->pending_cents)->toBe(7000);
    expect($balance->paid_cents)->toBe(0);

    $payouts = app(\App\Services\PayoutService::class);

    // The reconciliation job runs later and discovers the truth.
    $payouts->reconcile($item);

    // A duplicate/late reconciliation for the same item must not re-apply the result.
    $payouts->reconcile($item);

    $item->refresh();
    $balance->refresh();

    expect($item->status)->toBe(PayoutItemStatus::Succeeded);
    expect($balance->paid_cents)->toBe(7000);
    expect($balance->pending_cents)->toBe(0);
});

test('a timeout whose money never actually moved fails cleanly and frees the balance for a retry', function () {
    forceProviderOutcome('failed', reveal: false, reason: 'insufficient_funds');
    \Illuminate\Support\Facades\Bus::fake([CheckPayoutStatusJob::class]);

    $plan = SubscriptionPlan::factory()->monthly()->create(['term_days' => 30, 'price_cents' => 3000, 'platform_fee_bps' => 0]);
    [, , $instructors] = fundedSubscription($plan, instructorCount: 1, startedDaysAgo: 30);
    $instructor = $instructors->first();

    PayInstructorJob::dispatchSync($instructor->id);
    $item = PayoutItem::where('instructor_id', $instructor->id)->sole();
    expect($item->status)->toBe(PayoutItemStatus::Processing);

    app(\App\Services\PayoutService::class)->reconcile($item);

    $item->refresh();
    $balance = InstructorBalance::query()->where('instructor_id', $instructor->id)->first();

    expect($item->status)->toBe(PayoutItemStatus::Failed);
    expect($balance->pending_cents)->toBe(0);
    expect($balance->paid_cents)->toBe(0);

    // Now that the reservation was released, a fresh payout attempt can succeed.
    forceProviderOutcome('succeeded');
    PayInstructorJob::dispatchSync($instructor->id);

    $balance->refresh();
    expect($balance->paid_cents)->toBe(3000);
    expect(PayoutItem::where('instructor_id', $instructor->id)->count())->toBe(2);
});

test('two concurrent workers racing on the same instructor still only pay once', function () {
    forceProviderOutcome('succeeded');

    $plan = SubscriptionPlan::factory()->monthly()->create(['term_days' => 30, 'price_cents' => 8000, 'platform_fee_bps' => 0]);
    [, , $instructors] = fundedSubscription($plan, instructorCount: 1, startedDaysAgo: 30);
    $instructor = $instructors->first();

    $payouts = app(PayoutService::class);

    // Two "workers" both resolve claimOrResume before either settles — the row lock
    // inside claimOrResume serialises them, so the second sees the first's claim.
    $itemA = $payouts->claimOrResume($instructor);
    $itemB = $payouts->claimOrResume($instructor);

    expect($itemA->id)->toBe($itemB->id);

    $payouts->settle($itemA);
    $payouts->settle($itemB); // guarded no-op: already resolved

    $balance = InstructorBalance::query()->where('instructor_id', $instructor->id)->first();
    expect($balance->paid_cents)->toBe(8000);
    expect(PayoutItem::where('instructor_id', $instructor->id)->count())->toBe(1);
});
