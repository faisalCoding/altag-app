<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * The day a prepaying stage's fee is due.
 *
 * A booking awaiting its fee now holds its buses like any other, so it needs a
 * day on which that hold runs out. Kept on the booking rather than computed
 * from the setting each time it is read: the manager may move the deadline day
 * later, and a booking already made must keep the deadline it was given.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('bus_bookings', function (Blueprint $table) {
            $table->date('fee_due_on')->nullable()->after('fee_paid_at')->index();
        });

        // Bookings made under the old rule held nothing and had no deadline. The
        // day of the trip is the latest one that means anything for them.
        DB::table('bus_bookings')
            ->where('status', 'pending')
            ->whereNull('fee_due_on')
            ->update(['fee_due_on' => DB::raw('date')]);
    }

    public function down(): void
    {
        Schema::table('bus_bookings', function (Blueprint $table) {
            $table->dropColumn('fee_due_on');
        });
    }
};
