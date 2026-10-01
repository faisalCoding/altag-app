<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * The teacher app creates extra points offline and may send the same one
     * twice when a connection drops mid-request. It names each row with its
     * own id, so a resend finds the row it already made instead of adding a
     * second one. Rows added on the web carry none.
     */
    public function up(): void
    {
        Schema::table('leaderboard_extra_points', function (Blueprint $table) {
            $table->uuid('uuid')->nullable()->unique()->after('id');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('leaderboard_extra_points', function (Blueprint $table) {
            $table->dropUnique(['uuid']);
            $table->dropColumn('uuid');
        });
    }
};
