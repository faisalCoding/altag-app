<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * One date format for one column.
     *
     * student_plan_days.date drifted into holding two: bare "2026-07-07" on some
     * rows and "2026-07-07 00:00:00" on others. On SQLite the column is text, so
     * a range filter compares strings — the bare rows answer a bare bound and the
     * rest do not, which made date-filtered reports drop roughly half their rows
     * with no error to show for it.
     *
     * Everything is normalised to the full form, because that is what Eloquent's
     * `date` cast writes on every save; normalising the other way would re-mix on
     * the next write.
     */
    public function up(): void
    {
        if (DB::connection()->getDriverName() !== 'sqlite') {
            return; // A real DATE/DATETIME column cannot hold two formats.
        }

        DB::table('student_plan_days')
            ->whereRaw('length(date) = 10')
            ->update(['date' => DB::raw("date || ' 00:00:00'")]);
    }

    /**
     * Not reversed: the two formats mean the same instant, and restoring the
     * split would only reinstate the bug.
     */
    public function down(): void {}
};
