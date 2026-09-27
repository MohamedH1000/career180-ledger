<?php

namespace Database\Factories;

use App\Enums\SubscriptionStatus;
use App\Models\SubscriptionPlan;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends \Illuminate\Database\Eloquent\Factories\Factory<\App\Models\Subscription>
 */
class SubscriptionFactory extends Factory
{
    public function definition(): array
    {
        $plan = SubscriptionPlan::factory()->monthly();
        $startsAt = CarbonImmutable::now()->startOfDay();

        return [
            'student_id' => User::factory(),
            'plan_id' => $plan,
            'status' => SubscriptionStatus::Active,
            'starts_at' => $startsAt,
            'term_ends_at' => $startsAt->addDays(30),
            'accrual_ends_at' => $startsAt->addDays(30),
        ];
    }

    public function forPlan(SubscriptionPlan $plan, ?CarbonImmutable $startsAt = null): static
    {
        $startsAt ??= CarbonImmutable::now()->startOfDay();
        $termEnds = $startsAt->addDays($plan->term_days);

        return $this->state(fn () => [
            'plan_id' => $plan->id,
            'starts_at' => $startsAt,
            'term_ends_at' => $termEnds,
            'accrual_ends_at' => $termEnds,
        ]);
    }
}
