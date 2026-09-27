<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Pure audit/grouping header for a `payouts:run` invocation. It has no bearing on
        // correctness: running the command twice, or having two batches in flight at once,
        // is safe because idempotency is enforced per-instructor (see instructor_balances
        // and payout_items), not per-batch.
        Schema::create('payout_batches', function (Blueprint $table) {
            $table->id();
            $table->timestamp('initiated_at');
            // scheduled | manual | api
            $table->string('trigger')->default('manual');
            // running | completed
            $table->string('status')->default('running');
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('payout_batches');
    }
};
