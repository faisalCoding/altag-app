<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * What a student with no plan recited and how it was graded: one row per
     * student, part (hifz or review) and day. The uuid is the teacher app's id
     * for the change that created the row, so an offline resend finds it.
     */
    public function up(): void
    {
        Schema::create('free_recitations', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->nullable()->unique();
            $table->foreignId('student_id')->constrained('users')->cascadeOnDelete();
            $table->foreignId('recorded_by')->nullable()->constrained('users')->nullOnDelete();
            $table->string('type', 10);
            $table->date('recited_on');
            $table->foreignId('from_ayah_id')->nullable()->constrained('ayahs')->nullOnDelete();
            $table->foreignId('to_ayah_id')->nullable()->constrained('ayahs')->nullOnDelete();
            $table->tinyInteger('achievement')->nullable()->comment('0: not heard, 1: acceptable, 2: good, 3: excellent');
            $table->timestamp('graded_at')->nullable();
            $table->timestamps();

            $table->unique(['student_id', 'type', 'recited_on']);
            $table->index(['student_id', 'graded_at']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('free_recitations');
    }
};
