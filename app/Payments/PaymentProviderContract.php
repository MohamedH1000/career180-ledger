<?php

namespace App\Payments;

interface PaymentProviderContract
{
    /**
     * Ask the provider to move money to an instructor. Must be safe to call more than
     * once with the same $idempotencyKey — a repeat call for a key the provider has
     * already seen must return the original outcome rather than moving money again.
     */
    public function payout(string $idempotencyKey, int $amountCents, string $payeeReference): ProviderResult;

    /**
     * Look up what actually happened for a previously-attempted idempotency key. Used to
     * resolve a Timeout result from payout(). Returns null if the provider has no record
     * of this key at all (e.g. it truly never received the request).
     */
    public function status(string $idempotencyKey): ?ProviderResult;
}
