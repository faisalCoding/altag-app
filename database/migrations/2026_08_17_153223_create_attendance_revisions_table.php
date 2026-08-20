<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Every change a teacher makes to an attendance cell, kept as its own row so
     * the sheet can show who touched a day, when, and — when the edit was made
     * on a day other than the one being marked — why.
     */
    public function up(): void
    {
        Schema::create('attendance_revisions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('attendance_id')->nullable()->constrained('attendances')->nullOnDelete();

            // Students live in `users` since the phase-4 role-table cutover, the
            // same table attendances.student_id was swapped over to.
            $table->foreignId('student_id')->constrained('users')->cascadeOnDelete();
            $table->foreignId('circle_id')->constrained('circles')->cascadeOnDelete();

            // The day being marked, not the day the edit was made.
            $table->date('date');

            // Null on either side means "no record": creating one, or clearing it.
            $table->string('old_status')->nullable();
            $table->string('new_status')->nullable();

            // Required by the sheet whenever edited_on differs from date.
            $table->text('reason')->nullable();
            $table->boolean('is_off_day_edit')->default(false);

            // The real calendar day the teacher made the change.
            $table->date('edited_on');
            $table->unsignedBigInteger('edited_by_id')->nullable();
            $table->string('edited_by_type')->nullable();

            $table->timestamps();

            $table->index(['circle_id', 'date']);
            $table->index(['student_id', 'date']);
            $table->index(['edited_by_id', 'edited_by_type']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('attendance_revisions');
    }
};
