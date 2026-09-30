<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * The order the academy wants its stages read in.
 *
 * Separate from `level`, which is the rung a circle climbs on promotion: a
 * stage can sit off the ladder entirely and still need a place in every list
 * the site draws.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('stages', function (Blueprint $table) {
            $table->unsignedSmallInteger('position')->default(0)->after('level')->index();
        });

        // Seeded from the ladder where there is one, alphabetically where there
        // is not, so an existing academy opens on the order it already had.
        $position = 0;

        foreach (DB::table('stages')->orderByRaw('level is null, level, name')->pluck('id') as $id) {
            DB::table('stages')->where('id', $id)->update(['position' => ++$position]);
        }
    }

    public function down(): void
    {
        Schema::table('stages', function (Blueprint $table) {
            $table->dropColumn('position');
        });
    }
};
