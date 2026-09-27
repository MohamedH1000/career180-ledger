<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('subscriptions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('student_id')->constrained('users')->cascadeOnDelete();
            $table->foreignId('plan_id')->constrained('subscription_plans');
            // active | refunded | cancelled | expired
            $table->string('status')->default('active');
            $table->date('starts_at');
            // Immutable: the full term the student paid for on day one. Never changes.
            $table->date('term_ends_at');
            // Mutable: where revenue actually stops accruing. Equals term_ends_at unless
            // a refund (or plan change) shortens it. This is the single field that makes
            // early-termination proration work without touching any earnings already recorded.
            $table->date('accrual_ends_at');
            $table->timestamp('cancelled_at')->nullable();
            $table->timestamps();

            $table->index('student_id');
            $table->index(['status', 'accrual_ends_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('subscriptions');
    }
};
