<?php

use App\Models\SubscriptionPlan;
use App\Services\PayoutService;

test('outstanding equals earned minus paid minus pending, at any point in time', function () {
    $plan = SubscriptionPlan::factory()->monthly()->create(['term_days' => 30, 'price_cents' => 9000, 'platform_fee_bps' => 0]);
    [, , $instructors] = fundedSubscription($plan, instructorCount: 1, startedDaysAgo: 10);
    $instructor = $instructors->first();

    $payouts = app(PayoutService::class);

    // 10/30 of the term has elapsed.
    expect($payouts->earnedToDateCents($instructor))->toBe(3000);
    expect($payouts->outstandingCents($instructor))->toBe(3000);

    $item = $payouts->claimOrResume($instructor);
    expect($item->amount_cents)->toBe(3000);

    // Once claimed, it's reserved (pending) — no longer "outstanding" for a second claim,
    // even though it also isn't "paid" yet.
    expect($payouts->outstandingCents($instructor))->toBe(0);
});

test('two instructors on the same subscription are tracked independently', function () {
    $plan = SubscriptionPlan::factory()->monthly()->create(['term_days' => 30, 'price_cents' => 10_000, 'platform_fee_bps' => 2000]);
    [, , $instructors] = fundedSubscription($plan, instructorCount: 2, startedDaysAgo: 30);

    $payouts = app(PayoutService::class);

    // Pool = 8000, split evenly across 2 instructors = 4000 each.
    foreach ($instructors as $instructor) {
        expect($payouts->earnedToDateCents($instructor))->toBe(4000);
    }
});
