<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * A plan day keeps the wird it was scheduled with. When a student recites
     * something else, the teacher records the actual range beside it, so the
     * plan never changes and the next wird can follow what was really heard.
     * Each part also remembers which teacher last recorded it.
     */
    public function up(): void
    {
        Schema::table('student_plan_days', function (Blueprint $table) {
            $table->foreignId('hifz_recited_from_ayah_id')->nullable()->constrained('ayahs')->nullOnDelete();
            $table->foreignId('hifz_recited_to_ayah_id')->nullable()->constrained('ayahs')->nullOnDelete();
            $table->foreignId('hifz_recorded_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('review_recited_from_ayah_id')->nullable()->constrained('ayahs')->nullOnDelete();
            $table->foreignId('review_recited_to_ayah_id')->nullable()->constrained('ayahs')->nullOnDelete();
            $table->foreignId('review_recorded_by')->nullable()->constrained('users')->nullOnDelete();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('student_plan_days', function (Blueprint $table) {
            foreach (['hifz', 'review'] as $part) {
                $table->dropConstrainedForeignId("{$part}_recited_from_ayah_id");
                $table->dropConstrainedForeignId("{$part}_recited_to_ayah_id");
                $table->dropConstrainedForeignId("{$part}_recorded_by");
            }
        });
    }
};
