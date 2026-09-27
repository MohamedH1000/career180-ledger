<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // One row per (subscription, instructor): this instructor's fixed, final share of
        // this payment for the *whole* term. It never changes after creation. What changes
        // over time is how much of it has been *earned* — computed live from the parent
        // subscription's starts_at/term_ends_at/accrual_ends_at, never stored per day.
        Schema::create('revenue_allocations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('subscription_id')->constrained('subscriptions');
            $table->foreignId('instructor_id')->constrained('instructors');
            $table->foreignId('payment_id')->constrained('payments');
            $table->unsignedBigInteger('gross_share_cents');
            $table->timestamps();

            // Guards RevenueAllocationService::allocate() being run twice for the same
            // payment (e.g. a retried "record payment" job) from creating duplicate shares.
            $table->unique(['payment_id', 'instructor_id']);
            $table->index(['instructor_id', 'subscription_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('revenue_allocations');
    }
};
