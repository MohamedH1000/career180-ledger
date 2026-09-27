<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Stands in for "the external payment provider's own database". It is what makes
        // the mock provider idempotent and capable of a real status check: the true
        // outcome is decided and stored here the first time an idempotency key is seen,
        // even if what we *tell the caller* that first time is "timeout".
        Schema::create('mock_provider_ledger', function (Blueprint $table) {
            $table->id();
            $table->string('idempotency_key')->unique();
            // succeeded | failed
            $table->string('actual_outcome');
            $table->string('provider_reference')->nullable();
            $table->string('failure_reason')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('mock_provider_ledger');
    }
};
