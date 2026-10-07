<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * When a late teacher arrived — "late" alone does not say whether by five
 * minutes or by an hour. The reason for a late, an absence or a leave goes in
 * the existing `notes`.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('teacher_attendances', function (Blueprint $table) {
            $table->time('arrived_at')->nullable()->after('status');
        });
    }

    public function down(): void
    {
        Schema::table('teacher_attendances', function (Blueprint $table) {
            $table->dropColumn('arrived_at');
        });
    }
};
