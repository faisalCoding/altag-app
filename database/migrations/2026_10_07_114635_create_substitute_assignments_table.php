<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A teacher standing in for a circle on one day — from any stage, granted by
 * the circle's supervisor or the manager, either by naming them as the
 * substitute of an absent teacher on the roll call or directly.
 *
 * On that day the circle is the substitute's to work in as its teacher is,
 * for that day's records only; the day after, it is gone from their pages
 * and their phone. Rows are kept as the record of who covered.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('substitute_assignments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('circle_id')->constrained()->cascadeOnDelete();
            $table->foreignId('teacher_id')->constrained('users')->cascadeOnDelete();
            $table->date('date');
            // Whom they stood in for, when the circle's teacher was away.
            $table->foreignId('absent_teacher_id')->nullable()->constrained('users')->nullOnDelete();
            // Set when the roll call made it: naming another substitute there, or
            // marking the teacher present again, takes it back.
            $table->foreignId('teacher_attendance_id')->nullable()->constrained('teacher_attendances')->cascadeOnDelete();
            $table->foreignId('granted_by_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('granted_by_role')->nullable();
            $table->timestamps();

            $table->unique(['circle_id', 'teacher_id', 'date']);
            $table->index(['teacher_id', 'date']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('substitute_assignments');
    }
};
