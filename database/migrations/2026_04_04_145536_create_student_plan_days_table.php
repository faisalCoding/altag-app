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
        Schema::create('student_plan_days', function (Blueprint $table) {
            $table->id();
            $table->foreignId('student_plan_id')->constrained()->cascadeOnDelete();
            $table->date('date');
            $table->string('day_name');
            $table->foreignId('from_ayah_id')->nullable()->constrained('ayahs');
            $table->foreignId('to_ayah_id')->nullable()->constrained('ayahs');
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('student_plan_days');
    }
};
