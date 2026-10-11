<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Teachers no longer set an exam's day: they mark the student ready, now
     * or by a day they expect, and the manager seats the ready ones in a
     * dated batch. An exam is 'nominated' until then and has no day of its
     * own; it is 'pending' once seated, with the batch's day.
     *
     * A day expected is not readiness: the teacher still confirms it, and is
     * reminded once when the day comes.
     */
    public function up(): void
    {
        Schema::table('student_exams', function (Blueprint $table) {
            $table->dateTime('date_time')->nullable()->change();
            $table->foreignId('exam_batch_id')->nullable()->after('exam_level_id')->constrained()->nullOnDelete();
            $table->date('expected_ready_on')->nullable()->after('status');
            $table->dateTime('ready_at')->nullable()->after('expected_ready_on');
            $table->foreignId('nominated_by')->nullable()->after('ready_at')->constrained('users')->nullOnDelete();
            $table->dateTime('ready_reminded_at')->nullable()->after('nominated_by');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('student_exams', function (Blueprint $table) {
            $table->dropConstrainedForeignId('exam_batch_id');
            $table->dropConstrainedForeignId('nominated_by');
            $table->dropColumn(['expected_ready_on', 'ready_at', 'ready_reminded_at']);
        });
    }
};
