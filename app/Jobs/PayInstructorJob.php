<?php

namespace App\Jobs;

use App\Enums\PayoutItemStatus;
use App\Models\Instructor;
use App\Services\PayoutService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

/**
 * Pays one instructor whatever they're currently owed.
 *
 * Idempotent by construction, not by luck:
 *  - ShouldBeUnique collapses duplicate dispatches for the same instructor (an
 *    overlapping schedule, a manual re-trigger, two servers) into a single queued job
 *    while one is still pending/running.
 *  - Even if that unique-job lock is unavailable or expires and two copies run at once
 *    anyway, PayoutService::claimOrResume() takes a row lock on the instructor's
 *    balance and serialises them: the second one either resumes the first's unresolved
 *    claim or finds nothing left outstanding.
 *  - If the worker crashes mid-way (after claiming, before/during the provider call),
 *    the retried job calls claimOrResume() again, finds the same unresolved item, and
 *    resumes it with the *same* idempotency key — so even a provider call that already
 *    went through is recognised as a repeat, not charged/paid twice.
 */
class PayInstructorJob implements ShouldQueue, ShouldBeUnique
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 5;

    public function __construct(
        public readonly int $instructorId,
        public readonly ?int $payoutBatchId = null,
    ) {}

    public function uniqueId(): string
    {
        return (string) $this->instructorId;
    }

    public function uniqueFor(): int
    {
        return 600;
    }

    public function handle(PayoutService $payouts): void
    {
        $instructor = Instructor::query()->findOrFail($this->instructorId);

        $item = $payouts->claimOrResume($instructor);

        if ($item === null) {
            return;
        }

        if ($this->payoutBatchId !== null) {
            $payouts->attachToBatch($item, $this->payoutBatchId);
        }

        $payouts->settle($item);

        $item->refresh();

        // A Timeout outcome from the provider leaves the item in "processing" — the
        // truth isn't known yet. Schedule a reconciliation check to find out later.
        if ($item->status === PayoutItemStatus::Processing) {
            CheckPayoutStatusJob::dispatch($item->id)->delay(now()->addSeconds(30));
        }
    }
}
