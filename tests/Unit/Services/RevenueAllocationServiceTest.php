<?php

use App\Models\Course;
use App\Models\Instructor;
use App\Models\Payment;
use App\Models\RevenueAllocation;
use App\Models\Subscription;
use App\Models\SubscriptionPlan;
use App\Services\RevenueAllocationService;

test('splits the instructor pool evenly and hands the platform its fee off the top', function () {
    $plan = SubscriptionPlan::factory()->create([
        'price_cents' => 10_000,
        'platform_fee_bps' => 3000, // 30%
    ]);
    $subscription = Subscription::factory()->forPlan($plan)->create();

    $instructors = Instructor::factory()->count(2)->create()->sortBy('id')->values();
    foreach ($instructors as $instructor) {
        attachInstructor($subscription, $instructor);
    }

    $payment = Payment::factory()->create([
        'subscription_id' => $subscription->id,
        'amount_cents' => 10_000,
    ]);

    app(RevenueAllocationService::class)->allocate($payment);

    $allocations = RevenueAllocation::query()->where('payment_id', $payment->id)->get();

    // Pool = 10000 - 30% = 7000, split across 2 instructors = 3500 each.
    expect($allocations)->toHaveCount(2);
    expect($allocations->sum('gross_share_cents'))->toBe(7000);
    expect($allocations->pluck('gross_share_cents')->unique()->values()->all())->toBe([3500]);
});

test('a remainder cent goes to the lowest instructor ids, and the sum always equals the pool exactly', function () {
    $plan = SubscriptionPlan::factory()->create([
        'price_cents' => 10_001, // deliberately awkward
        'platform_fee_bps' => 3000,
    ]);
    $subscription = Subscription::factory()->forPlan($plan)->create();

    $instructors = Instructor::factory()->count(3)->create()->sortBy('id')->values();
    foreach ($instructors as $instructor) {
        attachInstructor($subscription, $instructor);
    }

    $payment = Payment::factory()->create([
        'subscription_id' => $subscription->id,
        'amount_cents' => 10_001,
    ]);

    app(RevenueAllocationService::class)->allocate($payment);

    $allocations = RevenueAllocation::query()
        ->where('payment_id', $payment->id)
        ->join('instructors', 'instructors.id', '=', 'revenue_allocations.instructor_id')
        ->orderBy('instructors.id')
        ->select('revenue_allocations.*')
        ->get();

    $pool = 10_001 - intdiv(10_001 * 3000, 10_000);

    expect($allocations->sum('gross_share_cents'))->toBe($pool);
    // Pool isn't divisible by 3, so exactly one instructor gets an extra cent — the
    // lowest id, deterministically.
    $shares = $allocations->pluck('gross_share_cents')->all();
    expect(max($shares) - min($shares))->toBeLessThanOrEqual(1);
    expect($allocations->first()->gross_share_cents)->toBe(max($shares));
});

test('allocating the same payment twice is a no-op (idempotent)', function () {
    [$subscription, $payment] = fundedSubscription(instructorCount: 2);

    $before = RevenueAllocation::query()->where('payment_id', $payment->id)->get();

    app(RevenueAllocationService::class)->allocate($payment);

    $after = RevenueAllocation::query()->where('payment_id', $payment->id)->get();

    expect($after)->toHaveCount($before->count());
    expect($after->sum('gross_share_cents'))->toBe($before->sum('gross_share_cents'));
});

test('a subscription with no enrolled instructors allocates nothing and does not error', function () {
    $subscription = Subscription::factory()->create();
    $payment = Payment::factory()->create([
        'subscription_id' => $subscription->id,
        'amount_cents' => 5000,
    ]);

    app(RevenueAllocationService::class)->allocate($payment);

    expect(RevenueAllocation::query()->where('payment_id', $payment->id)->count())->toBe(0);
});
