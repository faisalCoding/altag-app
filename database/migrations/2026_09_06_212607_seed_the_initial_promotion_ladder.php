<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * A first draft of the ladder, so the manager arrives at a screen that already
 * reads like the academy rather than a page of empty boxes.
 *
 * Only ever fills a blank rank. A rank set by hand — before this runs, or on a
 * database where it has run once already — is left exactly as it was found, and
 * names that do not exist here are simply skipped.
 *
 * The two circles left without a rank are deliberate: "النورانية" is a track
 * rather than a grade, and "جامعيين" spans several years, so neither should
 * move students automatically until the manager says otherwise.
 */
return new class extends Migration
{
    /** @var array<string, int> */
    private const STAGE_RANKS = [
        'الإبتدائية الاولية' => 1,
        'الابتدائية العليا' => 2,
        'السنابل' => 3,
        'الرواد' => 4,
        'ثانوية' => 5,
    ];

    /** @var array<string, int> */
    private const CIRCLE_RANKS = [
        'أولى و ثاني ابتدائي' => 1,
        'ثالث ابتدائي' => 2,

        'رابع ابتدائي' => 1,
        'خامس ابتدائي' => 2,

        'سادس ابتدائي' => 1,
        'اولى متوسط 1' => 2,
        'اولى متوسط 2' => 2,

        'ثاني متوسط' => 1,
        'ثالث متوسط' => 2,

        'اولى ثانوي' => 1,
        'أولى' => 1,
        'ثاني ثانوي' => 2,
        'ثالثة ثانوي' => 3,
    ];

    public function up(): void
    {
        $this->applyRanks('stages', self::STAGE_RANKS);
        $this->applyRanks('circles', self::CIRCLE_RANKS);
    }

    /**
     * @param  array<string, int>  $ranks
     */
    private function applyRanks(string $table, array $ranks): void
    {
        $wanted = [];

        foreach ($ranks as $name => $rank) {
            $wanted[$this->normalise($name)] = $rank;
        }

        foreach (DB::table($table)->whereNull('level')->get() as $row) {
            $rank = $wanted[$this->normalise($row->name)] ?? null;

            if ($rank !== null) {
                DB::table($table)->where('id', $row->id)->update(['level' => $rank]);
            }
        }
    }

    /**
     * Names in this database carry invisible bidi marks and stray spaces — one
     * stage begins with U+200F — so matching has to look past them.
     */
    private function normalise(string $name): string
    {
        return trim(preg_replace('/[\x{200E}\x{200F}\x{202A}-\x{202E}\x{2066}-\x{2069}\s]+/u', ' ', $name));
    }

    public function down(): void
    {
        DB::table('stages')->whereIn('name', array_keys(self::STAGE_RANKS))->update(['level' => null]);
        DB::table('circles')->whereIn('name', array_keys(self::CIRCLE_RANKS))->update(['level' => null]);
    }
};
