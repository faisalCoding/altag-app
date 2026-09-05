<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * What a form needs before it can be asked of anyone rather than merely
     * published as a link: who it is for, when it is due, and whether it holds
     * the app closed until it is answered.
     */
    public function up(): void
    {
        Schema::table('forms', function (Blueprint $table) {
            // Same shape as academic_calendar_events.shared_with: role flags,
            // explicit ids, and stage/circle scopes.
            $table->json('audience')->nullable();

            $table->string('status')->default('draft'); // draft | published | closed
            $table->timestamp('published_at')->nullable();
            $table->date('due_date')->nullable();

            // Off by default, and deliberately per-form: a survey that locks the
            // app is a blunt instrument, and making it the default would mean one
            // careless audience could shut everybody out at once.
            $table->boolean('is_blocking')->default(false);

            $table->index('status');
        });
    }

    public function down(): void
    {
        Schema::table('forms', function (Blueprint $table) {
            $table->dropIndex(['status']);
            $table->dropColumn(['audience', 'status', 'published_at', 'due_date', 'is_blocking']);
        });
    }
};
