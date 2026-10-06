<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Tasmeeh turn booking set by the supervisor for their stages, and each
 * circle's queue of turns.
 *
 * Teachers used to set the booking window themselves, one session per group of
 * teachers sharing circles. The supervisor now sets one window for all their
 * stages, written onto each stage, and every circle numbers its own queue from
 * one. The teachers' sessions and the turns booked in them stay in their
 * tables, no longer read.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('stages', function (Blueprint $table) {
            $table->boolean('turn_booking_enabled')->default(false);
            // Carbon's day numbers, 0 for Sunday.
            $table->json('turn_booking_days')->nullable();
            // "H:i" in the academy's time.
            $table->string('turn_booking_starts_at', 5)->nullable();
            $table->string('turn_booking_ends_at', 5)->nullable();
        });

        Schema::create('circle_turns', function (Blueprint $table) {
            $table->id();
            $table->foreignId('circle_id')->constrained()->cascadeOnDelete();
            $table->foreignId('student_id')->constrained('users')->cascadeOnDelete();
            $table->date('date');
            $table->unsignedInteger('turn_number');
            $table->timestamps();

            $table->unique(['circle_id', 'student_id', 'date'], 'circle_turns_student_day');
            $table->unique(['circle_id', 'date', 'turn_number'], 'circle_turns_number_day');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('circle_turns');

        Schema::table('stages', function (Blueprint $table) {
            $table->dropColumn(['turn_booking_enabled', 'turn_booking_days', 'turn_booking_starts_at', 'turn_booking_ends_at']);
        });
    }
};
