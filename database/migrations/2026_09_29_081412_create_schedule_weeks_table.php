<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('schedule_weeks', function (Blueprint $table) {
            $table->id();
            $table->foreignId('schedule_track_id')->constrained()->cascadeOnDelete();
            $table->unsignedSmallInteger('week_number');
            $table->string('title')->nullable();
            $table->string('note')->nullable();
            $table->boolean('is_draft')->default(false);
            $table->json('memo')->nullable();
            $table->timestamps();

            $table->unique(['schedule_track_id', 'week_number']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('schedule_weeks');
    }
};
