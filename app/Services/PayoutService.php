<?php

namespace App\Services;

use App\Enums\PayoutItemStatus;
use App\Enums\ProviderOutcome;
use App\Models\Instructor;
use App\Models\InstructorBalance;
use App\Models\PayoutItem;
use App\Models\ProviderTransaction;
use App\Payments\PaymentProviderContract;
use App\Payments\ProviderResult;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * The only code path allowed to move money in or out of instructor_balances. Every
 * public method here either runs inside its own DB transaction with a row lock on the
 * instructor's balance, or delegates to one that does — that row lock is what makes the
 * whole payout pipeline safe under concurrent/duplicate execution (see /docs/ARCHITECTURE.md).
 */
class PayoutService
{
    public function __construct(private readonly PaymentProviderContract $provider) {}

    /**
     * How much this instructor is earned-to-date but neither paid nor already reserved
     * by an in-flight payout attempt. Always computed live — never trusts any cache.
     */
    public function outstandingCents(Instructor $instructor, ?CarbonImmutable $asOf = null): int
    {
        $earned = $this->earnedToDateCents($instructor, $asOf);
        $balance = InstructorBalance::query()->firstOrCreate(['instructor_id' => $instructor->id]);

        return $earned - $balance->paid_cents - $balance->pending_cents;
    }

    public function earnedToDateCents(Instructor $instructor, ?CarbonImmutable $asOf = null): int
    {
        return $instructor->revenueAllocations()
            ->with('subscription')
            ->get()
            ->sum(fn ($allocation) => $allocation->earnedCentsAsOf($asOf));
    }

    /**
     * Claims whatever is currently outstanding for this instructor, or — if a previous
     * attempt already claimed money and never resolved (crash, timeout, retry) — returns
     * that same unresolved item instead of claiming again. This is what makes the whole
     * pipeline safe to retry from scratch at any point: there is never more than one
     * unresolved claim per instructor, and a fresh call either resumes it or finds
     * nothing left to claim.
     */
    public function claimOrResume(Instructor $instructor): ?PayoutItem
    {
        return DB::transaction(function () use ($instructor) {
            $balance = InstructorBalance::query()
                ->where('instructor_id', $instructor->id)
                ->lockForUpdate()
                ->first();

            if (! $balance) {
                try {
                    InstructorBalance::query()->create(['instructor_id' => $instructor->id]);
                } catch (\Illuminate\Database\QueryException) {
                    // Lost a race with a concurrent claim creating the same row first —
                    // fine, we just re-read it below with the lock.
                }

                $balance = InstructorBalance::query()
                    ->where('instructor_id', $instructor->id)
                    ->lockForUpdate()
                    ->first();
            }

            $existing = PayoutItem::query()
                ->where('instructor_id', $instructor->id)
                ->whereIn('status', [PayoutItemStatus::Pending, PayoutItemStatus::Processing])
                ->first();

            if ($existing) {
                return $existing;
            }

            $earned = $this->earnedToDateCents($instructor);
            $outstanding = $earned - $balance->paid_cents - $balance->pending_cents;

            if ($outstanding <= 0) {
                return null;
            }

            $item = PayoutItem::query()->create([
                'instructor_id' => $instructor->id,
                'amount_cents' => $outstanding,
                'status' => PayoutItemStatus::Pending,
                'idempotency_key' => (string) Str::uuid(),
            ]);

            $balance->increment('pending_cents', $outstanding);

            return $item;
        });
    }

    /** Attaches a claimed item to a batch for reporting purposes only. */
    public function attachToBatch(PayoutItem $item, int $batchId): void
    {
        if ($item->payout_batch_id === null) {
            $item->update(['payout_batch_id' => $batchId]);
        }
    }

    /**
     * Sends (or resumes sending) a claimed item to the provider and reacts to whatever
     * it says. Safe to call again on the same item after a crash: the item's
     * idempotency_key never changes, so a repeat call to the provider is recognised as
     * the same operation rather than a new one.
     */
    public function settle(PayoutItem $item): void
    {
        $item->refresh();

        if (! $item->isUnresolved()) {
            // Already succeeded or failed. This is a cheap best-effort short-circuit to
            // avoid pointlessly calling the provider again — the actual safety guarantee
            // against double-applying a result lives in applyResult()'s locked recheck.
            return;
        }

        $item->update(['status' => PayoutItemStatus::Processing]);

        $result = $this->provider->payout(
            $item->idempotency_key,
            $item->amount_cents,
            $item->instructor->payout_account_reference,
        );

        $this->recordAttempt($item, $result);
        $this->applyResult($item, $result);
    }

    /**
     * Re-checks the provider for an item stuck in "processing" (a previous timeout).
     * Called by CheckPayoutStatusJob on a backoff schedule.
     */
    public function reconcile(PayoutItem $item): void
    {
        $item->refresh();

        if ($item->status !== PayoutItemStatus::Processing) {
            // Already resolved (e.g. by a concurrent worker). This is a best-effort
            // short-circuit — applyResult()'s locked recheck is what actually guarantees
            // a result is never applied twice.
            return;
        }

        // Count the attempt regardless of outcome, so the backoff/manual-review ceiling
        // in CheckPayoutStatusJob can eventually kick in even if the provider never
        // resolves.
        $item->increment('status_check_attempts');

        $result = $this->provider->status($item->idempotency_key);

        if ($result === null) {
            return;
        }

        $this->recordAttempt($item, $result, isStatusCheck: true);
        $this->applyResult($item, $result);
    }

    /**
     * Applies a provider result to the ledger. Critically, this re-fetches and locks
     * the payout_item row itself before trusting its status — never the possibly-stale
     * in-memory $item passed in. Two callers can independently reach this method for the
     * same logical item (e.g. two in-memory copies from two concurrent claimOrResume()
     * calls, or a job and its own retry racing after a crash); without the fresh lock +
     * recheck, both would see "unresolved" and both would move the balance. With it, the
     * second caller's transaction blocks until the first commits, then sees the item is
     * already resolved and does nothing.
     */
    private function applyResult(PayoutItem $item, ProviderResult $result): void
    {
        match ($result->outcome) {
            ProviderOutcome::Succeeded => DB::transaction(function () use ($item, $result) {
                $locked = PayoutItem::query()->whereKey($item->id)->lockForUpdate()->first();

                if (! $locked->isUnresolved()) {
                    return;
                }

                $balance = InstructorBalance::query()
                    ->where('instructor_id', $locked->instructor_id)
                    ->lockForUpdate()
                    ->first();

                $balance->decrement('pending_cents', $locked->amount_cents);
                $balance->increment('paid_cents', $locked->amount_cents);

                $locked->update([
                    'status' => PayoutItemStatus::Succeeded,
                    'provider_reference' => $result->reference,
                ]);
            }),
            ProviderOutcome::Failed => DB::transaction(function () use ($item, $result) {
                $locked = PayoutItem::query()->whereKey($item->id)->lockForUpdate()->first();

                if (! $locked->isUnresolved()) {
                    return;
                }

                $balance = InstructorBalance::query()
                    ->where('instructor_id', $locked->instructor_id)
                    ->lockForUpdate()
                    ->first();

                $balance->decrement('pending_cents', $locked->amount_cents);

                $locked->update([
                    'status' => PayoutItemStatus::Failed,
                    'failure_reason' => $result->reason,
                ]);
            }),
            // Leave status = Processing and pending_cents reserved. A later
            // CheckPayoutStatusJob will call reconcile() to resolve it.
            ProviderOutcome::Timeout => null,
        };
    }

    private function recordAttempt(PayoutItem $item, ProviderResult $result, bool $isStatusCheck = false): void
    {
        ProviderTransaction::query()->create([
            'payout_item_id' => $item->id,
            'idempotency_key' => $item->idempotency_key,
            'attempt' => $isStatusCheck ? $item->status_check_attempts : 1,
            'outcome' => $result->outcome->value,
            'provider_reference' => $result->reference,
            'raw_payload' => ['reason' => $result->reason],
        ]);
    }
}
