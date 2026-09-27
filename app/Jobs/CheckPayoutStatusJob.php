<?php

namespace App\Jobs;

use App\Enums\PayoutItemStatus;
use App\Models\PayoutItem;
use App\Services\PayoutService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

/**
 * Resolves a payout item left in "processing" after the provider timed out. Keeps
 * asking the provider's status endpoint with backoff until it gets a definitive answer,
 * up to MAX_ATTEMPTS — at which point it stops guessing and flags the item for a human
 * instead of assuming either outcome.
 */
class CheckPayoutStatusJob implements ShouldQueue, ShouldBeUnique
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    private const MAX_ATTEMPTS = 6;

    public function __construct(public readonly int $payoutItemId) {}

    public function uniqueId(): string
    {
        return (string) $this->payoutItemId;
    }

    public function uniqueFor(): int
    {
        return 60;
    }

    public function handle(PayoutService $payouts): void
    {
        $item = PayoutItem::query()->find($this->payoutItemId);

        if (! $item || $item->status !== PayoutItemStatus::Processing) {
            return; // already resolved by another worker, or nothing to do
        }

        $payouts->reconcile($item);

        $item->refresh();

        if ($item->status !== PayoutItemStatus::Processing) {
            return; // resolved
        }

        if ($item->status_check_attempts >= self::MAX_ATTEMPTS) {
            $item->update(['requires_manual_review' => true]);

            return;
        }

        // Exponential-ish backoff: 30s, 60s, 120s, ...
        $delay = 30 * (2 ** $item->status_check_attempts);
        self::dispatch($item->id)->delay(now()->addSeconds($delay));
    }
}
