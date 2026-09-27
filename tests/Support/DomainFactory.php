<?php

use App\Models\Course;
use App\Models\Instructor;
use App\Models\Payment;
use App\Models\Subscription;
use App\Models\SubscriptionPlan;
use App\Services\RevenueAllocationService;
use Carbon\CarbonImmutable;
use Illuminate\Support\Str;

function attachInstructor(Subscription $subscription, Instructor $instructor): void
{
    $course = Course::factory()->create(['instructor_id' => $instructor->id]);
    $subscription->instructors()->attach($instructor->id, ['course_id' => $course->id]);
}

/**
 * Builds a subscription that has already paid and been allocated to $instructorCount
 * distinct instructors (one course each), started $startedDaysAgo days ago on the given
 * plan. Returns the subscription, the payment, and the instructors in allocation order.
 *
 * @return array{0: Subscription, 1: Payment, 2: \Illuminate\Support\Collection<int, Instructor>}
 */
function fundedSubscription(
    ?SubscriptionPlan $plan = null,
    int $instructorCount = 2,
    int $startedDaysAgo = 0,
): array {
    $plan ??= SubscriptionPlan::factory()->monthly()->create();

    $startsAt = CarbonImmutable::now()->startOfDay()->subDays($startedDaysAgo);

    $subscription = Subscription::factory()->forPlan($plan, $startsAt)->create();

    $instructors = Instructor::factory()->count($instructorCount)->create();

    foreach ($instructors as $instructor) {
        attachInstructor($subscription, $instructor);
    }

    $payment = Payment::factory()->create([
        'subscription_id' => $subscription->id,
        'amount_cents' => $plan->price_cents,
        'idempotency_key' => (string) Str::uuid(),
    ]);

    app(RevenueAllocationService::class)->allocate($payment);

    return [$subscription, $payment, $instructors->sortBy('id')->values()];
}
