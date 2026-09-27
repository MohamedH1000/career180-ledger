<?php

namespace Database\Seeders;

use App\Jobs\PayInstructorJob;
use App\Models\Course;
use App\Models\Instructor;
use App\Models\Payment;
use App\Models\Subscription;
use App\Models\SubscriptionPlan;
use App\Models\User;
use App\Services\RefundService;
use App\Services\RevenueAllocationService;
use Carbon\CarbonImmutable;
use Illuminate\Database\Seeder;
use Illuminate\Support\Str;

/**
 * A small, fast demo dataset (not the 500k-subscription production scale the design
 * targets — see /docs/ARCHITECTURE.md for how that scale is handled). Enough to see
 * real, non-zero instructor balances and payout history in the Filament screen.
 */
class DatabaseSeeder extends Seeder
{
    public function run(): void
    {
        $admin = User::factory()->admin()->create([
            'name' => 'Career 180 Admin',
            'email' => 'admin@career180.test',
        ]);

        $plans = [
            'monthly' => SubscriptionPlan::factory()->monthly()->create(),
            'quarterly' => SubscriptionPlan::factory()->quarterly()->create(),
            'annual' => SubscriptionPlan::factory()->annual()->create(),
        ];

        $instructors = Instructor::factory()
            ->count(15)
            ->has(Course::factory()->count(3), 'courses')
            ->create();

        $allocationService = app(RevenueAllocationService::class);
        $refundService = app(RefundService::class);

        foreach (range(1, 60) as $i) {
            $plan = $plans[array_rand($plans)];
            $startedDaysAgo = fake()->numberBetween(1, max(1, $plan->term_days - 1));
            $startsAt = CarbonImmutable::now()->startOfDay()->subDays($startedDaysAgo);

            $subscription = Subscription::factory()->forPlan($plan, $startsAt)->create([
                'student_id' => User::factory()->create()->id,
            ]);

            $courses = Course::inRandomOrder()->limit(fake()->numberBetween(2, 5))->get();

            foreach ($courses as $course) {
                $subscription->instructors()->attach($course->instructor_id, ['course_id' => $course->id]);
            }

            $payment = Payment::factory()->create([
                'subscription_id' => $subscription->id,
                'amount_cents' => $plan->price_cents,
                'idempotency_key' => (string) Str::uuid(),
            ]);

            $allocationService->allocate($payment);

            // A handful of students refund partway through, to demo proration.
            if ($i % 11 === 0) {
                $refundService->refund(
                    $subscription,
                    $startsAt->addDays(intdiv($plan->term_days, 3)),
                    'Seeded demo refund',
                );
            }
        }

        // Run one real payout pass synchronously so the Filament screen shows history.
        Instructor::query()->each(fn (Instructor $instructor) => PayInstructorJob::dispatchSync($instructor->id));

        \Illuminate\Support\Facades\Artisan::call('payouts:refresh-earnings');

        $this->command?->info('Seeded demo data. Admin login: admin@career180.test / password');
    }
}
