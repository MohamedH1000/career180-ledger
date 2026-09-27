<?php

use App\Enums\PaymentStatus;
use App\Enums\SubscriptionStatus;
use App\Models\RevenueAllocation;
use App\Models\SubscriptionPlan;
use App\Services\RefundService;
use Carbon\CarbonImmutable;

test('a refund halfway through a term freezes accrual and refunds the unearned half', function () {
    $plan = SubscriptionPlan::factory()->monthly()->create(['term_days' => 30, 'price_cents' => 6000, 'platform_fee_bps' => 0]);
    [$subscription, $payment, $instructors] = fundedSubscription($plan, instructorCount: 1, startedDaysAgo: 15);

    $refund = app(RefundService::class)->refund($subscription, CarbonImmutable::now(), 'student cancelled');

    $subscription->refresh();
    $payment->refresh();

    expect($subscription->status)->toBe(SubscriptionStatus::Refunded);
    expect($subscription->accrual_ends_at->toDateString())->toBe(CarbonImmutable::now()->startOfDay()->toDateString());
    expect($refund->amount_cents)->toBe(3000); // half the term remained
    expect($payment->status)->toBe(PaymentStatus::PartiallyRefunded);

    $allocation = RevenueAllocation::query()->where('instructor_id', $instructors->first()->id)->first();

    // Frozen at the refund date, forever — even asking "as of" a month later.
    expect($allocation->earnedCentsAsOf(CarbonImmutable::now()))->toBe(3000);
    expect($allocation->earnedCentsAsOf(CarbonImmutable::now()->addMonth()))->toBe(3000);
});

test('a full-term refund at day zero refunds the entire payment', function () {
    $plan = SubscriptionPlan::factory()->monthly()->create(['term_days' => 30, 'price_cents' => 6000, 'platform_fee_bps' => 0]);
    [$subscription, $payment] = fundedSubscription($plan, instructorCount: 1, startedDaysAgo: 0);

    $refund = app(RefundService::class)->refund($subscription, CarbonImmutable::now(), 'immediate cancellation');

    expect($refund->amount_cents)->toBe(6000);
    $payment->refresh();
    expect($payment->status)->toBe(PaymentStatus::Refunded);
});

test('refunding after the term has already fully elapsed refunds nothing', function () {
    $plan = SubscriptionPlan::factory()->monthly()->create(['term_days' => 30, 'price_cents' => 6000, 'platform_fee_bps' => 0]);
    [$subscription, $payment] = fundedSubscription($plan, instructorCount: 1, startedDaysAgo: 30);

    $refund = app(RefundService::class)->refund($subscription, CarbonImmutable::now(), 'late request');

    expect($refund->amount_cents)->toBe(0);
});
