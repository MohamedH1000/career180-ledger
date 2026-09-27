<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // The single row per instructor that every payout attempt locks with
        // SELECT ... FOR UPDATE. paid_cents/pending_cents are the only authoritative,
        // mutated-in-a-transaction fields in the whole system; everything else about
        // "how much is owed" is derived live from revenue_allocations.
        Schema::create('instructor_balances', function (Blueprint $table) {
            $table->id();
            $table->foreignId('instructor_id')->unique()->constrained('instructors');
            // Confirmed, provider-succeeded payouts.
            $table->unsignedBigInteger('paid_cents')->default(0);
            // Claimed by an in-flight payout attempt but not yet confirmed succeeded or
            // failed. Reserved so a concurrent/duplicate run can't claim the same money.
            $table->unsignedBigInteger('pending_cents')->default(0);
            // Non-authoritative read cache for the Filament dashboard, refreshed
            // periodically. Payout logic never trusts this column.
            $table->unsignedBigInteger('cached_earned_cents')->default(0);
            $table->timestamp('cached_at')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('instructor_balances');
    }
};
