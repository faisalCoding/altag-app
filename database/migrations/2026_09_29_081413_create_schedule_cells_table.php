<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('schedule_cells', function (Blueprint $table) {
            $table->id();
            $table->foreignId('schedule_week_id')->constrained()->cascadeOnDelete();
            $table->unsignedTinyInteger('weekday');
            $table->unsignedTinyInteger('position');
            $table->unsignedTinyInteger('span')->default(1);
            $table->foreignId('schedule_activity_id')->nullable()->constrained()->nullOnDelete();
            $table->string('title')->nullable();
            $table->string('detail')->nullable();
            $table->string('time', 32)->nullable();
            $table->timestamps();

            $table->unique(['schedule_week_id', 'weekday', 'position']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('schedule_cells');
    }
};
