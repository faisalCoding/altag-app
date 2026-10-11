<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * A sitting of the association's exam: the day, hour and place the manager
     * sets for a group of students their teachers marked ready.
     */
    public function up(): void
    {
        Schema::create('exam_batches', function (Blueprint $table) {
            $table->id();
            $table->dateTime('date_time');
            $table->string('location')->nullable();
            $table->text('notes')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('exam_batches');
    }
};
