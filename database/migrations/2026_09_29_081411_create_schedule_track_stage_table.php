<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('schedule_track_stage', function (Blueprint $table) {
            $table->foreignId('schedule_track_id')->constrained()->cascadeOnDelete();
            $table->foreignId('stage_id')->constrained()->cascadeOnDelete();
            $table->primary(['schedule_track_id', 'stage_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('schedule_track_stage');
    }
};
