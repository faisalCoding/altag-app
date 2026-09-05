<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Forms were a supervisor's alone. Managers ask the whole academy and
     * teachers ask their own circles, so ownership becomes a morph — the same
     * created_by pair academic_calendar_events already uses.
     *
     * supervisor_id is kept and backfilled from rather than dropped: several
     * screens still read it, and a column nobody writes any more is cheaper than
     * a migration that has to find every reader first.
     */
    public function up(): void
    {
        Schema::table('forms', function (Blueprint $table) {
            $table->unsignedBigInteger('created_by_id')->nullable()->after('supervisor_id');
            $table->string('created_by_type')->nullable()->after('created_by_id');
            $table->index(['created_by_id', 'created_by_type']);
        });

        // Every form that exists today belongs to the supervisor who made it.
        DB::table('forms')->whereNotNull('supervisor_id')->update([
            'created_by_id' => DB::raw('supervisor_id'),
            'created_by_type' => 'supervisor',
        ]);

        // Only now can the old column stop being required.
        Schema::table('forms', function (Blueprint $table) {
            $table->unsignedBigInteger('supervisor_id')->nullable()->change();
        });
    }

    public function down(): void
    {
        Schema::table('forms', function (Blueprint $table) {
            $table->dropIndex(['created_by_id', 'created_by_type']);
            $table->dropColumn(['created_by_id', 'created_by_type']);
        });
    }
};
