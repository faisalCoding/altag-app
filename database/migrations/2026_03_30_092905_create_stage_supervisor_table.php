<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('stage_supervisor', function (Blueprint $table) {
            $table->foreignId('stage_id')->constrained()->cascadeOnDelete();
            $table->foreignId('supervisor_id')->constrained('supervisors')->cascadeOnDelete();
            $table->primary(['stage_id', 'supervisor_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('stage_supervisor');
    }
};
