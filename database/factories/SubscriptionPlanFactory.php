<?php

namespace Database\Factories;

use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends \Illuminate\Database\Eloquent\Factories\Factory<\App\Models\SubscriptionPlan>
 */
class SubscriptionPlanFactory extends Factory
{
    public function definition(): array
    {
        return [
            'code' => 'monthly_'.fake()->unique()->numerify('###'),
            'name' => 'Monthly',
            'term_days' => 30,
            'price_cents' => 50_00,
            'platform_fee_bps' => 3000,
        ];
    }

    public function monthly(): static
    {
        return $this->state(fn () => [
            'code' => 'monthly',
            'name' => 'Monthly',
            'term_days' => 30,
            'price_cents' => 50_00,
            'platform_fee_bps' => 3000,
        ]);
    }

    public function quarterly(): static
    {
        return $this->state(fn () => [
            'code' => 'quarterly',
            'name' => '3-Month',
            'term_days' => 90,
            'price_cents' => 135_00,
            'platform_fee_bps' => 3000,
        ]);
    }

    public function annual(): static
    {
        return $this->state(fn () => [
            'code' => 'annual',
            'name' => 'Annual',
            'term_days' => 365,
            'price_cents' => 480_00,
            'platform_fee_bps' => 2000,
        ]);
    }
}
