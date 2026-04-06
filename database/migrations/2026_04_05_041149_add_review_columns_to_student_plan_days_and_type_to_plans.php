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
            $table->foreignId('review_from_ayah_id')->nullable()->constrained('ayahs')->nullOnDelete();
            $table->foreignId('review_to_ayah_id')->nullable()->constrained('ayahs')->nullOnDelete();

            // Allow from_ayah_id and to_ayah_id to be nullable since a plan might be review-only
            $table->foreignId('from_ayah_id')->nullable()->change();
            $table->foreignId('to_ayah_id')->nullable()->change();
        });

        Schema::table('student_plans', function (Blueprint $table) {
            $table->string('plan_type')->default('hifz'); // 'hifz', 'review', 'hifz_review'
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('student_plan_days', function (Blueprint $table) {
            $table->dropForeign(['review_from_ayah_id']);
            $table->dropForeign(['review_to_ayah_id']);
            $table->dropColumn(['review_from_ayah_id', 'review_to_ayah_id']);
        });

        Schema::table('student_plans', function (Blueprint $table) {
            $table->dropColumn('plan_type');
        });
    }
};
