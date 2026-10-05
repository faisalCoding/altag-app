<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * The highest level the student's own page has celebrated with them.
     *
     * notified_level says which level-up the news feed has announced; this one
     * says which the student has seen celebrated, so the level card shows once
     * per level whichever device they open the page on. It starts where the
     * announcements stand, so no level reached before today is celebrated late.
     * Null (no baseline yet) celebrates nothing.
     */
    public function up(): void
    {
        Schema::table('gamification_student_states', function (Blueprint $table) {
            $table->unsignedInteger('celebrated_level')->nullable()->after('notified_level');
        });

        DB::table('gamification_student_states')
            ->whereNotNull('notified_level')
            ->update(['celebrated_level' => DB::raw('notified_level')]);
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('gamification_student_states', function (Blueprint $table) {
            $table->dropColumn('celebrated_level');
        });
    }
};
