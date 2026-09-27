<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('refunds', function (Blueprint $table) {
            $table->id();
            $table->foreignId('payment_id')->constrained('payments');
            $table->foreignId('subscription_id')->constrained('subscriptions');
            // The cash amount refunded to the student (prorated by remaining days).
            $table->unsignedBigInteger('amount_cents');
            // The subscription's accrual_ends_at was set to this date as a result.
            $table->date('effective_date');
            $table->string('reason')->nullable();
            $table->timestamps();

            $table->index('subscription_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('refunds');
    }
};
