<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('ayahs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('surah_id')->constrained('surahs')->cascadeOnDelete();
            $table->integer('verse_number');
            $table->string('verse_key');
            $table->integer('juz_number');
            $table->integer('hizb_number');
            $table->integer('rub_number');
            $table->integer('page_number');
            $table->integer('ruku_number');
            $table->integer('manzil_number');
            $table->string('sajdah_type')->nullable();
            $table->text('text_uthmani');
            $table->timestamps();

            $table->unique(['surah_id', 'verse_number']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('ayahs');
    }
};
