<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Who covered for a teacher who was away — a record of the day, so the report
 * says who stood in, not only who did not come. Kept in the trail as well.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('teacher_attendances', function (Blueprint $table) {
            $table->foreignId('substitute_teacher_id')->nullable()->after('notes')->constrained('users')->nullOnDelete();
        });

        Schema::table('teacher_attendance_revisions', function (Blueprint $table) {
            $table->foreignId('old_substitute_id')->nullable()->after('new_arrived_at')->constrained('users')->nullOnDelete();
            $table->foreignId('new_substitute_id')->nullable()->after('old_substitute_id')->constrained('users')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('teacher_attendance_revisions', function (Blueprint $table) {
            $table->dropConstrainedForeignId('new_substitute_id');
            $table->dropConstrainedForeignId('old_substitute_id');
        });

        Schema::table('teacher_attendances', function (Blueprint $table) {
            $table->dropConstrainedForeignId('substitute_teacher_id');
        });
    }
};
