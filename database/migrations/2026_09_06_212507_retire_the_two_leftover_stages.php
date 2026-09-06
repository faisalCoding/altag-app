<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Two stages do not belong on the ladder.
 *
 * "الاولية ( المودة )" holds one empty circle and nothing refers to it. Its
 * secondary namesake holds the circle that is really the first rung of ثانوية,
 * so the circle moves there and the stage goes.
 *
 * Matched by name rather than id, and every step guarded, so the migration is
 * safe to run against a database where someone has already tidied part of this
 * by hand — and does nothing at all where the names never existed.
 */
return new class extends Migration
{
    private const RETIRED_PRIMARY = 'الاولية ( المودة ) ';

    private const RETIRED_SECONDARY = 'الثانوية الاولية';

    private const SECONDARY = 'ثانوية';

    public function up(): void
    {
        $this->retireEmptyPrimaryStage();
        $this->foldSecondaryStageIntoItsParent();
    }

    /**
     * Nothing points at this stage, and its only circle has no students, so both
     * can go. A circle that has since gained a student is left alone.
     */
    private function retireEmptyPrimaryStage(): void
    {
        $stage = $this->findStage(self::RETIRED_PRIMARY);

        if (! $stage) {
            return;
        }

        $occupied = DB::table('users')
            ->join('circles', 'users.circle_id', '=', 'circles.id')
            ->where('circles.stage_id', $stage->id)
            ->exists();

        if ($occupied || DB::table('users')->where('stage_id', $stage->id)->exists()) {
            return;
        }

        DB::table('circles')->where('stage_id', $stage->id)->delete();
        DB::table('stages')->where('id', $stage->id)->delete();
    }

    /**
     * The circle becomes the first rung of ثانوية. Its students carry a direct
     * stage_id pointing at the stage being removed — harmless while the circle
     * decides their stage, but a dangling reference once the row is gone, so it
     * is cleared rather than left to rot.
     */
    private function foldSecondaryStageIntoItsParent(): void
    {
        $stage = $this->findStage(self::RETIRED_SECONDARY);
        $parent = $this->findStage(self::SECONDARY);

        if (! $stage || ! $parent) {
            return;
        }

        DB::table('users')->where('stage_id', $stage->id)->update(['stage_id' => null]);
        DB::table('circles')->where('stage_id', $stage->id)->update(['stage_id' => $parent->id]);
        DB::table('stages')->where('id', $stage->id)->delete();
    }

    private function findStage(string $name): ?object
    {
        return DB::table('stages')->get()
            ->first(fn ($stage) => $this->normalise($stage->name) === $this->normalise($name));
    }

    /**
     * Names in this database carry invisible bidi marks and stray spaces — one
     * stage begins with U+200F — so matching has to look past them.
     */
    private function normalise(string $name): string
    {
        return trim(preg_replace('/[\x{200E}\x{200F}\x{202A}-\x{202E}\x{2066}-\x{2069}\s]+/u', ' ', $name));
    }

    /**
     * The retired stages carried no information worth restoring — an empty
     * circle and a misfiled parent — and the circle keeps its students either
     * way, so there is nothing to undo.
     */
    public function down(): void {}
};
