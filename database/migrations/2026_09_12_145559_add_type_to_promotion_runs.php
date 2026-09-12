<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Merging two circles moves a body of students in one stroke, exactly as a
 * promotion does, and wants the same undo. It is recorded as a run of its own
 * kind rather than a second mechanism that would need its own way back.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('promotion_runs', function (Blueprint $table) {
            $table->string('type')->default('promotion')->after('name')->index();
        });
    }

    public function down(): void
    {
        Schema::table('promotion_runs', function (Blueprint $table) {
            $table->dropIndex(['type']);
            $table->dropColumn('type');
        });
    }
};
