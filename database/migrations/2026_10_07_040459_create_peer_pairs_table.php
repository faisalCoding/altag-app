<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The day's mutual-recitation pairs of a circle, kept so every teacher of the
 * circle — on the site or the app — sees the same ones, can swap two students
 * between them, and records how each recitation went.
 *
 * A pair is two places, first and second. In a mutual pair each recites their
 * portion to the other; otherwise the first recites and the second listens.
 * Each place keeps the portion its student recites and the outcome: the
 * mistakes counted and whether the student is ready for the teacher.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('peer_pairs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('circle_id')->constrained()->cascadeOnDelete();
            // "Y-m-d", the academy's day.
            $table->string('date', 10);
            $table->unsignedInteger('position');
            $table->boolean('mutual');

            foreach (['first', 'second'] as $place) {
                $table->foreignId("{$place}_id")->constrained('users')->cascadeOnDelete();
                $table->foreignId("{$place}_from_ayah_id")->nullable()->constrained('ayahs')->nullOnDelete();
                $table->foreignId("{$place}_to_ayah_id")->nullable()->constrained('ayahs')->nullOnDelete();
                $table->unsignedSmallInteger("{$place}_mistakes")->nullable();
                $table->boolean("{$place}_ready")->nullable();
            }

            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index(['circle_id', 'date']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('peer_pairs');
    }
};
