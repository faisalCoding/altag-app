<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * الإقرارات: what the students of a stage, or their guardians, give their
 * word on — a pledge, the circles' policies, principles or guidelines — each
 * written by a supervisor for stages of theirs.
 *
 * An agreement holds its draft; publishing freezes it as a version, and a
 * signature names the version its signer read, with a hash of that very text,
 * so a later edit never changes what anyone was shown. A teacher may call an
 * agreement by another of the kinds for their own circle.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('agreements', function (Blueprint $table) {
            $table->id();
            $table->string('title');
            $table->string('type', 20);
            $table->string('audience', 20);
            $table->json('clauses');
            $table->string('status', 20)->default('draft');
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });

        Schema::create('agreement_stage', function (Blueprint $table) {
            $table->foreignId('agreement_id')->constrained()->cascadeOnDelete();
            $table->foreignId('stage_id')->constrained()->cascadeOnDelete();

            $table->primary(['agreement_id', 'stage_id']);
        });

        Schema::create('agreement_versions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('agreement_id')->constrained()->cascadeOnDelete();
            $table->unsignedInteger('number');
            $table->string('title');
            $table->string('type', 20);
            $table->string('audience', 20);
            $table->json('clauses');
            $table->boolean('requires_acknowledgement')->default(true);
            $table->foreignId('published_by')->nullable()->constrained('users')->nullOnDelete();
            $table->dateTime('published_at');
            $table->timestamps();

            $table->unique(['agreement_id', 'number']);
        });

        Schema::create('agreement_circle_types', function (Blueprint $table) {
            $table->id();
            $table->foreignId('agreement_id')->constrained()->cascadeOnDelete();
            $table->foreignId('circle_id')->constrained()->cascadeOnDelete();
            $table->string('type', 20);
            $table->foreignId('set_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->unique(['agreement_id', 'circle_id']);
        });

        Schema::create('agreement_signatures', function (Blueprint $table) {
            $table->id();
            $table->foreignId('agreement_id')->constrained()->cascadeOnDelete();
            $table->foreignId('agreement_version_id')->constrained()->cascadeOnDelete();
            $table->foreignId('student_id')->constrained('users')->cascadeOnDelete();
            $table->string('party', 20);
            $table->string('signer_name');
            $table->foreignId('signer_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('channel', 20);
            $table->string('type', 20);
            $table->string('content_hash', 64);
            $table->string('ip', 45)->nullable();
            $table->string('user_agent', 500)->nullable();
            $table->dateTime('signed_at');
            $table->timestamps();

            $table->unique(['agreement_version_id', 'student_id', 'party']);
            $table->index(['agreement_id', 'student_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('agreement_signatures');
        Schema::dropIfExists('agreement_circle_types');
        Schema::dropIfExists('agreement_versions');
        Schema::dropIfExists('agreement_stage');
        Schema::dropIfExists('agreements');
    }
};
