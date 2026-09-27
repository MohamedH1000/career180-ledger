<?php

namespace App\Console\Commands;

use App\Jobs\PayInstructorJob;
use App\Models\Instructor;
use App\Models\PayoutBatch;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Cache;

/**
 * php artisan payouts:run
 *
 * Dispatches one PayInstructorJob per instructor. Safe to run more than once
 * concurrently — from an overlapping schedule, a manual re-trigger, or two servers at
 * once — because correctness lives in the per-instructor claim (PayoutService +
 * ShouldBeUnique on the job), not here.
 *
 * The Cache::lock below is purely an efficiency nicety to stop two overlapping
 * invocations from both walking the whole instructors table and dispatching duplicate
 * jobs; it is not what prevents double-paying. If it's unavailable (cache down, or
 * this ran on a box with no shared cache) this command still degrades safely — it just
 * might dispatch a few redundant jobs, which the per-instructor lock downstream absorbs.
 */
class RunInstructorPayouts extends Command
{
    protected $signature = 'payouts:run {--chunk=500}';

    protected $description = 'Pay every instructor whatever they are currently owed.';

    public function handle(): int
    {
        $lock = Cache::lock('payouts:run-dispatch', 300);

        if (! $lock->get()) {
            $this->warn('Another payout run is currently dispatching jobs. Skipping this invocation.');

            return self::SUCCESS;
        }

        try {
            $batch = PayoutBatch::query()->create([
                'initiated_at' => now(),
                'trigger' => 'manual',
                'status' => 'running',
            ]);

            $dispatched = 0;

            Instructor::query()->orderBy('id')->chunkById((int) $this->option('chunk'), function ($instructors) use ($batch, &$dispatched) {
                foreach ($instructors as $instructor) {
                    PayInstructorJob::dispatch($instructor->id, $batch->id);
                    $dispatched++;
                }
            });

            $batch->update(['status' => 'completed']);

            $this->info("Dispatched {$dispatched} payout job(s) in batch #{$batch->id}.");
        } finally {
            $lock->release();
        }

        return self::SUCCESS;
    }
}
