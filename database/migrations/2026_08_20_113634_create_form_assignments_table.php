<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * One row per person a form is asked of.
     *
     * The audience rule on the form says who should answer; this table is that
     * rule made concrete, and it is the only thing that can answer "who has not
     * answered yet" — a question a public link could never answer, since it
     * knows only who arrived, never who was meant to.
     */
    public function up(): void
    {
        Schema::create('form_assignments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('form_id')->constrained('forms')->cascadeOnDelete();

            // Every role lives in `users` since the phase-4 role-table cutover.
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->string('role'); // the role they were asked in

            $table->string('status')->default('pending'); // pending | completed
            $table->date('due_date')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->foreignId('form_response_id')->nullable()->constrained('form_responses')->nullOnDelete();

            $table->timestamp('notified_at')->nullable();
            $table->timestamp('reminded_at')->nullable();

            $table->timestamps();

            // Asking the same person twice for one form is never intended, and the
            // constraint is what lets the audience be re-run to pick up newcomers
            // without duplicating everyone already asked.
            $table->unique(['form_id', 'user_id']);
            $table->index(['user_id', 'status']);
            $table->index(['form_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('form_assignments');
    }
};
