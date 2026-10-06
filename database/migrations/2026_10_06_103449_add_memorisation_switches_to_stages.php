<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Whether a stage memorises the mutun (hadith texts) and the odes at all.
 *
 * Two switches rather than one: a stage may take on the odes and not the
 * mutun. Both start on, so turning this out changes nothing until a
 * supervisor decides otherwise for one of their stages.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('stages', function (Blueprint $table) {
            $table->boolean('hadith_enabled')->default(true)->after('require_edit_reason');
            $table->boolean('odes_enabled')->default(true)->after('hadith_enabled');
        });
    }

    public function down(): void
    {
        Schema::table('stages', function (Blueprint $table) {
            $table->dropColumn(['hadith_enabled', 'odes_enabled']);
        });
    }
};
