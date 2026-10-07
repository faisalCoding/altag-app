<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Every change to a teacher's day, as the students' attendance_revisions keep
 * theirs: the day itself only holds where it ended up, so who marked it, who
 * changed it and who cleared it live here. Rows are written, never updated.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('teacher_attendance_revisions', function (Blueprint $table) {
            $table->id();
            // Kept after the day is cleared — the trail outlives the record.
            $table->foreignId('teacher_attendance_id')->nullable()->constrained('teacher_attendances')->nullOnDelete();
            $table->foreignId('teacher_id')->constrained('users')->cascadeOnDelete();
            $table->foreignId('stage_id')->nullable()->constrained()->nullOnDelete();
            $table->date('date');

            // A null old status is a first mark; a null new one, a cleared day.
            $table->string('old_status')->nullable();
            $table->string('new_status')->nullable();
            $table->text('old_notes')->nullable();
            $table->text('new_notes')->nullable();
            $table->time('old_arrived_at')->nullable();
            $table->time('new_arrived_at')->nullable();

            $table->foreignId('edited_by_id')->nullable()->constrained('users')->nullOnDelete();
            // One person can be both: which hat they wore when they made it.
            $table->string('edited_by_role')->nullable();
            $table->timestamps();

            $table->index(['teacher_id', 'date']);
            $table->index(['stage_id', 'date']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('teacher_attendance_revisions');
    }
};
