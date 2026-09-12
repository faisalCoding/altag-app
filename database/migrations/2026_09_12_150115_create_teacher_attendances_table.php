<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Whether a teacher turned up, kept apart from the students' register.
 *
 * `attendances` is about a student on a day in a circle, and its `teacher_id`
 * records who took the register rather than who was present — so a teacher's
 * own attendance cannot live there without making that column mean two things.
 *
 * A teacher is present or absent as a person, not once per circle they hold,
 * so the day is unique per teacher.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('teacher_attendances', function (Blueprint $table) {
            $table->id();
            $table->foreignId('teacher_id')->constrained('users')->cascadeOnDelete();
            $table->date('date');
            $table->string('status');
            $table->text('notes')->nullable();
            $table->foreignId('recorded_by_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->unique(['teacher_id', 'date']);
            $table->index('date');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('teacher_attendances');
    }
};
