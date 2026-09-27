<?php

use App\Enums\ProviderOutcome;
use App\Payments\MockPaymentProvider;

test('a repeated call with the same idempotency key never re-decides the outcome', function () {
    $calls = 0;
    $provider = new MockPaymentProvider(function () use (&$calls) {
        $calls++;

        return ['outcome' => 'succeeded', 'reveal' => true];
    });

    $first = $provider->payout('key-1', 1000, 'wallet_1');
    $second = $provider->payout('key-1', 1000, 'wallet_1');

    expect($calls)->toBe(1); // the decider only ever runs once for this key
    expect($first->outcome)->toBe(ProviderOutcome::Succeeded);
    expect($second->outcome)->toBe(ProviderOutcome::Succeeded);
    expect($second->reference)->toBe($first->reference);
});

test('a timeout hides the true outcome once, but status() and a retried payout() reveal it', function () {
    $provider = new MockPaymentProvider(fn () => ['outcome' => 'succeeded', 'reveal' => false]);

    $first = $provider->payout('key-2', 1000, 'wallet_1');
    expect($first->outcome)->toBe(ProviderOutcome::Timeout);

    // The money actually moved on the provider's side, discoverable via status().
    $status = $provider->status('key-2');
    expect($status->outcome)->toBe(ProviderOutcome::Succeeded);

    // And a retried payout() call with the same key now reveals the truth too.
    $retry = $provider->payout('key-2', 1000, 'wallet_1');
    expect($retry->outcome)->toBe(ProviderOutcome::Succeeded);
});

test('a timed-out call whose money never actually moved resolves to failed', function () {
    $provider = new MockPaymentProvider(fn () => ['outcome' => 'failed', 'reveal' => false, 'reason' => 'insufficient_funds']);

    $first = $provider->payout('key-3', 1000, 'wallet_1');
    expect($first->outcome)->toBe(ProviderOutcome::Timeout);

    $status = $provider->status('key-3');
    expect($status->outcome)->toBe(ProviderOutcome::Failed);
});

test('status() on a key the provider has never seen returns null', function () {
    $provider = new MockPaymentProvider;

    expect($provider->status('never-seen'))->toBeNull();
});

test('concurrent-looking calls with the same fresh key never disagree on the outcome', function () {
    // Simulates two racing callers both hitting payout() for a brand-new key before
    // either has committed: the first write wins, the second reads it back instead of
    // deciding again.
    $decisions = 0;
    $provider = new MockPaymentProvider(function () use (&$decisions) {
        $decisions++;

        return ['outcome' => $decisions === 1 ? 'succeeded' : 'failed', 'reveal' => true];
    });

    $a = $provider->payout('shared-key', 1000, 'wallet_1');
    $b = $provider->payout('shared-key', 1000, 'wallet_1');

    expect($a->outcome)->toBe($b->outcome);
});
