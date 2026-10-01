<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('schedule_activities', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('icon', 32);
            $table->string('color', 16);
            $table->string('second_icon', 32)->nullable();
            $table->string('second_color', 16)->nullable();
            $table->string('default_time', 32)->nullable();
            $table->boolean('is_routine')->default(false);
            $table->json('topics')->nullable();
            $table->unsignedSmallInteger('sort_order')->default(0);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('schedule_activities');
    }
};
