<?php

namespace Database\Factories;

use App\Enums\PaymentStatus;
use App\Models\Subscription;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends \Illuminate\Database\Eloquent\Factories\Factory<\App\Models\Payment>
 */
class PaymentFactory extends Factory
{
    public function definition(): array
    {
        return [
            'subscription_id' => Subscription::factory(),
            'amount_cents' => 50_00,
            'currency' => 'EGP',
            'status' => PaymentStatus::Succeeded,
            'paid_at' => now(),
            'idempotency_key' => (string) fake()->unique()->uuid(),
        ];
    }
}
