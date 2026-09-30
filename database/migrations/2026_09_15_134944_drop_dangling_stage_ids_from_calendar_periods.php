<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Forget stages that no longer exist.
 *
 * An attendance period aims itself at stages through a JSON column, which no
 * foreign key watches. Retiring two stages therefore left periods pointing at
 * rows that had gone — invisible until someone opened one, where the stage
 * checkboxes are validated with `exists` and the period could no longer be
 * saved at all, over a stage it no longer showed.
 */
return new class extends Migration
{
    public function up(): void
    {
        $live = DB::table('stages')->pluck('id')->all();

        foreach (DB::table('academic_calendar_events')->whereNotNull('stage_ids')->get() as $event) {
            $ids = json_decode($event->stage_ids ?? '[]', true);

            if (! is_array($ids) || $ids === []) {
                continue;
            }

            $kept = array_values(array_filter($ids, fn ($id) => in_array((int) $id, $live, true)));

            if (count($kept) === count($ids)) {
                continue;
            }

            // Every stage it named is gone: the period reverts to academy-wide,
            // which is what an empty list has always meant here.
            DB::table('academic_calendar_events')
                ->where('id', $event->id)
                ->update(['stage_ids' => json_encode($kept)]);
        }
    }

    /**
     * The removed ids referred to stages that no longer exist; there is nothing
     * to put back.
     */
    public function down(): void {}
};
