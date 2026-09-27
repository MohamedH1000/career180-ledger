<?php

namespace App\Payments;

use App\Enums\ProviderOutcome;

final readonly class ProviderResult
{
    public function __construct(
        public ProviderOutcome $outcome,
        public ?string $reference = null,
        public ?string $reason = null,
    ) {}

    public static function succeeded(string $reference): self
    {
        return new self(ProviderOutcome::Succeeded, reference: $reference);
    }

    public static function failed(string $reason): self
    {
        return new self(ProviderOutcome::Failed, reason: $reason);
    }

    public static function timeout(): self
    {
        return new self(ProviderOutcome::Timeout);
    }
}
