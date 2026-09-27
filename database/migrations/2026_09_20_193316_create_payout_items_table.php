<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // One "claim" on a slice of an instructor's outstanding balance. Created inside the
        // same locked transaction that reserves the amount in instructor_balances.pending_cents,
        // so a payout_item existing is proof the money was reserved, never proof it was paid.
        Schema::create('payout_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('payout_batch_id')->nullable()->constrained('payout_batches');
            $table->foreignId('instructor_id')->constrained('instructors');
            $table->unsignedBigInteger('amount_cents');
            // pending (claimed, not yet sent) | processing (sent, awaiting outcome)
            // | succeeded | failed
            $table->string('status')->default('pending');
            // Generated once when the item is claimed and reused across every retry/resume
            // of this same claim, so the (mock) provider can recognise a repeated call as
            // the same operation instead of moving money twice.
            $table->string('idempotency_key')->unique();
            $table->string('provider_reference')->nullable();
            $table->string('failure_reason')->nullable();
            $table->unsignedInteger('status_check_attempts')->default(0);
            $table->boolean('requires_manual_review')->default(false);
            $table->timestamps();

            $table->index(['instructor_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('payout_items');
    }
};
