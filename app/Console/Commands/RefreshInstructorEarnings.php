<?php

namespace App\Console\Commands;

use App\Models\Instructor;
use App\Models\InstructorBalance;
use App\Services\PayoutService;
use Illuminate\Console\Command;

/**
 * php artisan payouts:refresh-earnings
 *
 * Recomputes instructor_balances.cached_earned_cents for every instructor. This is a
 * read-optimisation only — the payout pipeline never trusts this cache, it always
 * recomputes live under a lock (see PayoutService). At production scale (tens of
 * millions of revenue_allocations rows), computing "earned to date" live for every row
 * of an admin dashboard table is not viable, so the Filament screen reads this cache
 * instead, kept fresh by scheduling this command every few minutes.
 */
class RefreshInstructorEarnings extends Command
{
    protected $signature = 'payouts:refresh-earnings {--chunk=500}';

    protected $description = 'Refresh the cached earned-to-date figure used by the admin dashboard.';

    public function handle(PayoutService $payouts): int
    {
        $updated = 0;

        Instructor::query()->orderBy('id')->chunkById((int) $this->option('chunk'), function ($instructors) use ($payouts, &$updated) {
            foreach ($instructors as $instructor) {
                $earned = $payouts->earnedToDateCents($instructor);

                InstructorBalance::query()->updateOrCreate(
                    ['instructor_id' => $instructor->id],
                    ['cached_earned_cents' => $earned, 'cached_at' => now()],
                );

                $updated++;
            }
        });

        $this->info("Refreshed cached earnings for {$updated} instructor(s).");

        return self::SUCCESS;
    }
}
