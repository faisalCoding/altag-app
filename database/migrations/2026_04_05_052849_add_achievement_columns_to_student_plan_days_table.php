<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('student_plan_days', function (Blueprint $table) {
            $table->tinyInteger('hifz_achievement')->nullable()->comment('1: Weak, 2: Medium, 3: Excellent');
            $table->tinyInteger('review_achievement')->nullable()->comment('1: Weak, 2: Medium, 3: Excellent');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('student_plan_days', function (Blueprint $table) {
            $table->dropColumn(['hifz_achievement', 'review_achievement']);
        });
    }
};
