<?php

namespace App\Payments;

use App\Models\MockProviderLedger;
use Illuminate\Database\QueryException;
use Illuminate\Support\Str;

/**
 * Stands in for a real, unreliable external payment provider.
 *
 * The first time an idempotency key is seen, the provider internally decides — and
 * permanently records — the *actual* outcome (succeeded or failed). What it reports
 * back to the caller for that first call is randomised across three buckets:
 *
 *  - succeeds: reports the true "succeeded" result immediately.
 *  - failed permanently: reports the true "failed" result immediately.
 *  - times out: the money may or may not have actually moved (decided the same way
 *    internally), but the caller is told nothing conclusive. The truth can only be
 *    learned later via status(), or by calling payout() again with the same key.
 *
 * Any subsequent call (payout() retry, or status()) for a key already in the ledger
 * always returns the real, previously-decided outcome — never a repeated timeout —
 * exactly like a real provider's idempotency key would behave.
 */
class MockPaymentProvider implements PaymentProviderContract
{
    /** @var (callable(string,int,string): array{outcome: string, reveal: bool})|null */
    private $decider;

    public function __construct(?callable $decider = null)
    {
        $this->decider = $decider;
    }

    public function payout(string $idempotencyKey, int $amountCents, string $payeeReference): ProviderResult
    {
        $existing = MockProviderLedger::query()->where('idempotency_key', $idempotencyKey)->first();

        if ($existing) {
            return $this->toResult($existing);
        }

        $decision = $this->decide($idempotencyKey, $amountCents, $payeeReference);

        try {
            $ledger = MockProviderLedger::query()->create([
                'idempotency_key' => $idempotencyKey,
                'actual_outcome' => $decision['outcome'],
                'provider_reference' => $decision['outcome'] === 'succeeded' ? 'mock_'.Str::uuid() : null,
                'failure_reason' => $decision['outcome'] === 'failed' ? $decision['reason'] ?? 'declined' : null,
            ]);
        } catch (QueryException) {
            // Lost a race with a concurrent call using the same key: the other call's
            // decision is the one that counts, exactly like a real idempotent provider.
            $ledger = MockProviderLedger::query()->where('idempotency_key', $idempotencyKey)->firstOrFail();
        }

        if (! $decision['reveal']) {
            return ProviderResult::timeout();
        }

        return $this->toResult($ledger);
    }

    public function status(string $idempotencyKey): ?ProviderResult
    {
        $ledger = MockProviderLedger::query()->where('idempotency_key', $idempotencyKey)->first();

        return $ledger ? $this->toResult($ledger) : null;
    }

    /**
     * @return array{outcome: string, reveal: bool, reason?: string}
     */
    private function decide(string $idempotencyKey, int $amountCents, string $payeeReference): array
    {
        if ($this->decider) {
            return ($this->decider)($idempotencyKey, $amountCents, $payeeReference);
        }

        $roll = mt_rand(1, 100);

        return match (true) {
            $roll <= 70 => ['outcome' => 'succeeded', 'reveal' => true],
            $roll <= 85 => ['outcome' => 'failed', 'reveal' => true, 'reason' => 'issuer_declined'],
            // Timed out: the underlying money movement is still randomised, we just
            // don't tell the caller yet.
            default => ['outcome' => mt_rand(0, 1) === 1 ? 'succeeded' : 'failed', 'reveal' => false, 'reason' => 'issuer_declined'],
        };
    }

    private function toResult(MockProviderLedger $ledger): ProviderResult
    {
        return $ledger->actual_outcome === 'succeeded'
            ? ProviderResult::succeeded($ledger->provider_reference)
            : ProviderResult::failed($ledger->failure_reason ?? 'declined');
    }
}
