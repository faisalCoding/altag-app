<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * One student's place in a promotion: where they were, where they are going,
 * and what is being done to them.
 *
 * The "from" columns are what make the run reversible. Without them an applied
 * promotion is indistinguishable from the circles simply having always been
 * that way, and three hundred students cannot be put back.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('promotion_run_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('promotion_run_id')->constrained()->cascadeOnDelete();
            $table->foreignId('student_id')->constrained('users')->cascadeOnDelete();
            $table->foreignId('from_circle_id')->nullable()->constrained('circles')->nullOnDelete();
            $table->foreignId('to_circle_id')->nullable()->constrained('circles')->nullOnDelete();
            $table->string('from_status');
            $table->string('action');
            $table->timestamps();

            $table->unique(['promotion_run_id', 'student_id']);
            $table->index(['promotion_run_id', 'from_circle_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('promotion_run_items');
    }
};
