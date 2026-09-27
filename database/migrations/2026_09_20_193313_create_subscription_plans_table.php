<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('subscription_plans', function (Blueprint $table) {
            $table->id();
            // monthly | quarterly | annual
            $table->string('code')->unique();
            $table->string('name');
            $table->unsignedInteger('term_days');
            $table->unsignedBigInteger('price_cents');
            // Platform's cut, in basis points (1/100th of a percent). 3000 = 30%.
            $table->unsignedInteger('platform_fee_bps');
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('subscription_plans');
    }
};
