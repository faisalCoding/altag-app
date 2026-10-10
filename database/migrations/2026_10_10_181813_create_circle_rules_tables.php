<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * The circles' rules, which teachers and supervisors acknowledge.
     *
     * The manager edits a draft of sections and clauses, each clause meant
     * for everyone, for teachers or for supervisors. Publishing freezes the
     * draft into a version; an acknowledgement names the version and keeps a
     * hash of the very text its person read, so an edit made later never
     * changes what they were shown.
     */
    public function up(): void
    {
        Schema::create('rule_sections', function (Blueprint $table) {
            $table->id();
            $table->string('title');
            $table->unsignedInteger('position')->default(0);
            $table->timestamps();
        });

        Schema::create('rule_clauses', function (Blueprint $table) {
            $table->id();
            $table->foreignId('rule_section_id')->constrained()->cascadeOnDelete();
            $table->string('audience', 20);
            $table->text('body');
            $table->unsignedInteger('position')->default(0);
            $table->timestamps();
        });

        Schema::create('rule_versions', function (Blueprint $table) {
            $table->id();
            $table->unsignedInteger('number')->unique();
            $table->json('content');
            $table->boolean('requires_acknowledgement')->default(true);
            $table->text('notes')->nullable();
            $table->foreignId('published_by')->nullable()->constrained('users')->nullOnDelete();
            $table->dateTime('published_at');
            $table->timestamps();
        });

        Schema::create('rule_acknowledgements', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('rule_version_id')->constrained()->cascadeOnDelete();
            $table->json('roles');
            $table->string('content_hash', 64);
            $table->string('ip', 45)->nullable();
            $table->string('user_agent', 500)->nullable();
            $table->dateTime('acknowledged_at');
            $table->timestamps();

            $table->unique(['user_id', 'rule_version_id']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('rule_acknowledgements');
        Schema::dropIfExists('rule_versions');
        Schema::dropIfExists('rule_clauses');
        Schema::dropIfExists('rule_sections');
    }
};
