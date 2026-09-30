<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Whether a teacher must say why when marking attendance outside the session day.
 *
 * Kept on the stage rather than in the global settings table because
 * supervisors hold stages: a single switch would let one of them change the
 * rule for circles they do not supervise. Defaults to on, which is the rule as
 * it stood before anyone could turn it off.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('stages', function (Blueprint $table) {
            $table->boolean('require_edit_reason')->default(true)->after('level');
        });
    }

    public function down(): void
    {
        Schema::table('stages', function (Blueprint $table) {
            $table->dropColumn('require_edit_reason');
        });
    }
};
