<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * The WhatsApp group a teacher pastes the day's absence message into,
     * opened as soon as it is copied. The supervisor names one for a whole
     * stage, and may give a circle its own group that takes its place.
     */
    public function up(): void
    {
        Schema::table('stages', function (Blueprint $table) {
            $table->string('whatsapp_group_url')->nullable()->after('require_edit_reason');
        });

        Schema::table('circles', function (Blueprint $table) {
            $table->string('whatsapp_group_url')->nullable()->after('description');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('circles', function (Blueprint $table) {
            $table->dropColumn('whatsapp_group_url');
        });

        Schema::table('stages', function (Blueprint $table) {
            $table->dropColumn('whatsapp_group_url');
        });
    }
};
