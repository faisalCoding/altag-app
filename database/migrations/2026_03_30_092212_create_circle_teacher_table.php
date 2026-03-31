<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('circle_teacher', function (Blueprint $table) {
            $table->foreignId('circle_id')->constrained()->cascadeOnDelete();
            $table->foreignId('teacher_id')->constrained('teachers')->cascadeOnDelete();
            $table->primary(['circle_id', 'teacher_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('circle_teacher');
    }
};
