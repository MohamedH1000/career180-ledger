<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('payments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('subscription_id')->constrained('subscriptions');
            $table->unsignedBigInteger('amount_cents');
            $table->string('currency', 3)->default('EGP');
            // succeeded | refunded | partially_refunded
            $table->string('status')->default('succeeded');
            $table->timestamp('paid_at');
            // Protects against the checkout flow being retried/double-submitted and
            // charging (and therefore allocating) the same intent twice.
            $table->string('idempotency_key')->unique();
            $table->timestamps();

            $table->index('subscription_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('payments');
    }
};
