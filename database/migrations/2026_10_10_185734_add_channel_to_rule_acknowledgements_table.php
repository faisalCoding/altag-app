<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * How the word was given: from the person's own account, or through the
     * public link, where whoever holds it picks a name from a stage's list.
     */
    public function up(): void
    {
        Schema::table('rule_acknowledgements', function (Blueprint $table) {
            $table->string('channel', 20)->default('account')->after('roles');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('rule_acknowledgements', function (Blueprint $table) {
            $table->dropColumn('channel');
        });
    }
};
