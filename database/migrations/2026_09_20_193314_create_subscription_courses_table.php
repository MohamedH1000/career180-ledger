<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Snapshot, taken at subscribe time, of which courses (and therefore which
        // instructors) a subscription grants access to. We snapshot rather than joining
        // live against a catalogue so that revenue already promised to an instructor can
        // never be silently redirected by a later catalogue change (course removed/moved).
        Schema::create('subscription_courses', function (Blueprint $table) {
            $table->id();
            $table->foreignId('subscription_id')->constrained('subscriptions')->cascadeOnDelete();
            $table->foreignId('course_id')->constrained('courses');
            // Denormalised for fast "which instructors does this subscription pay" queries.
            $table->foreignId('instructor_id')->constrained('instructors');
            $table->timestamps();

            $table->unique(['subscription_id', 'course_id']);
            $table->index(['subscription_id', 'instructor_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('subscription_courses');
    }
};
