<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * The stage a teacher's day was taken under, kept on the day itself.
 *
 * Read off the teacher's circles at the time it would follow them: a teacher
 * who moves to another stage would carry every past day with them, out of the
 * reach of the supervisor who took it. Null for a teacher with no circle,
 * whom only the manager marks.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('teacher_attendances', function (Blueprint $table) {
            $table->foreignId('stage_id')->nullable()->after('teacher_id')->constrained()->nullOnDelete();
            $table->index(['stage_id', 'date']);
        });

        // The days taken so far go under the stage of the teacher's circles today.
        $stageByTeacher = DB::table('circle_teacher')
            ->join('circles', 'circles.id', '=', 'circle_teacher.circle_id')
            ->whereNotNull('circles.stage_id')
            ->orderBy('circles.id')
            ->get(['circle_teacher.teacher_id', 'circles.stage_id'])
            ->unique('teacher_id')
            ->pluck('stage_id', 'teacher_id');

        foreach ($stageByTeacher as $teacherId => $stageId) {
            DB::table('teacher_attendances')->where('teacher_id', $teacherId)->update(['stage_id' => $stageId]);
        }
    }

    public function down(): void
    {
        Schema::table('teacher_attendances', function (Blueprint $table) {
            $table->dropIndex(['stage_id', 'date']);
            $table->dropConstrainedForeignId('stage_id');
        });
    }
};
