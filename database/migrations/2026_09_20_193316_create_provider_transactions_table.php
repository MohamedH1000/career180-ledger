<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Audit trail of every call our side made to the (mock) payment provider, and what
        // it told us at the time — independent of what we later reconciled it to. Lets us
        // answer "why does this payout_item say succeeded" without trusting memory alone.
        Schema::create('provider_transactions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('payout_item_id')->constrained('payout_items');
            $table->string('idempotency_key');
            $table->unsignedInteger('attempt');
            // succeeded | failed | timeout
            $table->string('outcome');
            $table->string('provider_reference')->nullable();
            $table->json('raw_payload')->nullable();
            $table->timestamps();

            $table->index('idempotency_key');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('provider_transactions');
    }
};
