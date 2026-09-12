<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * One year's promotion, as a thing that exists before it happens.
 *
 * A run is drafted, reviewed and only then applied, because moving every
 * student at once is not an edit — it is an event the academy should be able to
 * look at, argue with, and undo.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('promotion_runs', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('status')->default('draft')->index();
            $table->foreignId('created_by_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('applied_at')->nullable();
            $table->timestamp('reverted_at')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('promotion_runs');
    }
};
