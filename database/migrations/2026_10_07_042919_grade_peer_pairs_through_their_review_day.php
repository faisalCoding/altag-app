<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A mutual recitation is graded, and the grade is the student's own for the
 * review they recited: each place keeps the plan day its portion comes from,
 * and the grade goes to that day's session as it does from the tasmeeh page.
 * The «ready for the teacher» mark it replaces goes.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('peer_pairs', function (Blueprint $table) {
            $table->foreignId('first_day_id')->nullable()->constrained('student_plan_days')->nullOnDelete();
            $table->foreignId('second_day_id')->nullable()->constrained('student_plan_days')->nullOnDelete();
        });

        Schema::table('peer_pairs', function (Blueprint $table) {
            $table->dropColumn(['first_ready', 'second_ready']);
        });
    }

    public function down(): void
    {
        Schema::table('peer_pairs', function (Blueprint $table) {
            $table->boolean('first_ready')->nullable();
            $table->boolean('second_ready')->nullable();
        });

        Schema::table('peer_pairs', function (Blueprint $table) {
            $table->dropConstrainedForeignId('first_day_id');
            $table->dropConstrainedForeignId('second_day_id');
        });
    }
};
