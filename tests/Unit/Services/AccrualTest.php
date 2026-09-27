<?php

use App\Models\RevenueAllocation;
use App\Models\SubscriptionPlan;
use Carbon\CarbonImmutable;

test('nothing is earned before the term starts', function () {
    $plan = SubscriptionPlan::factory()->monthly()->create(['term_days' => 30]);
    [, , $instructors] = fundedSubscription($plan, instructorCount: 1, startedDaysAgo: -5); // starts 5 days in the future

    $allocation = RevenueAllocation::query()->where('instructor_id', $instructors->first()->id)->first();

    expect($allocation->earnedCentsAsOf(CarbonImmutable::now()))->toBe(0);
});

test('exactly half a 30-day term is earned at the 15-day mark', function () {
    $plan = SubscriptionPlan::factory()->monthly()->create(['term_days' => 30, 'price_cents' => 6000, 'platform_fee_bps' => 0]);
    [, , $instructors] = fundedSubscription($plan, instructorCount: 1, startedDaysAgo: 15);

    $allocation = RevenueAllocation::query()->where('instructor_id', $instructors->first()->id)->first();

    expect($allocation->gross_share_cents)->toBe(6000);
    expect($allocation->earnedCentsAsOf(CarbonImmutable::now()))->toBe(3000);
});

test('the full share is earned once the term has fully elapsed, with no rounding drift', function () {
    // A price that does not divide evenly by term_days, to stress rounding.
    $plan = SubscriptionPlan::factory()->monthly()->create(['term_days' => 30, 'price_cents' => 10_007, 'platform_fee_bps' => 0]);
    [, , $instructors] = fundedSubscription($plan, instructorCount: 1, startedDaysAgo: 30);

    $allocation = RevenueAllocation::query()->where('instructor_id', $instructors->first()->id)->first();

    expect($allocation->earnedCentsAsOf(CarbonImmutable::now()))->toBe($allocation->gross_share_cents);

    // ...and stays there even further into the future — it never earns "more" than 100%.
    expect($allocation->earnedCentsAsOf(CarbonImmutable::now()->addDays(100)))->toBe($allocation->gross_share_cents);
});

test('earned-to-date is monotonically non-decreasing day over day', function () {
    $plan = SubscriptionPlan::factory()->monthly()->create(['term_days' => 30, 'price_cents' => 9_999, 'platform_fee_bps' => 0]);
    [$subscription, , $instructors] = fundedSubscription($plan, instructorCount: 1, startedDaysAgo: 0);
    $allocation = RevenueAllocation::query()->where('instructor_id', $instructors->first()->id)->first();

    $previous = 0;
    foreach (range(0, 30) as $day) {
        $earned = $allocation->earnedCentsAsOf($subscription->starts_at->clone()->addDays($day));
        expect($earned)->toBeGreaterThanOrEqual($previous);
        $previous = $earned;
    }

    expect($previous)->toBe($allocation->gross_share_cents);
});
