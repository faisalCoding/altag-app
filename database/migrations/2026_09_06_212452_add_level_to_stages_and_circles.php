<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The rung each stage and circle occupies on the promotion ladder.
 *
 * A circle's rank is not unique within its stage: two sections of the same
 * grade share one rank, which is what stops a promotion from moving students
 * sideways from section 1 into section 2. A null rank means the circle sits
 * outside the ladder entirely and no promotion ever moves its students.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('stages', function (Blueprint $table) {
            $table->unsignedSmallInteger('level')->nullable()->after('description');
        });

        Schema::table('circles', function (Blueprint $table) {
            $table->unsignedSmallInteger('level')->nullable()->after('description');
            $table->index(['stage_id', 'level']);
        });
    }

    public function down(): void
    {
        Schema::table('stages', function (Blueprint $table) {
            $table->dropColumn('level');
        });

        Schema::table('circles', function (Blueprint $table) {
            $table->dropIndex(['stage_id', 'level']);
            $table->dropColumn('level');
        });
    }
};
