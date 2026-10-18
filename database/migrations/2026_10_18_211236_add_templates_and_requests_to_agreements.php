<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Two kinds of agreement: a general one binds every student of its stages,
 * as before; a template binds nobody until a teacher sends it — to students
 * of theirs they pick, as often as they need, each send a request with its
 * own link, note and answer. The manager writes either for every stage.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('agreements', function (Blueprint $table) {
            $table->string('kind', 20)->default('general');
            $table->boolean('all_stages')->default(false);
            $table->string('author_role', 20)->default('supervisor');
        });

        Schema::create('agreement_requests', function (Blueprint $table) {
            $table->id();
            $table->foreignId('agreement_id')->constrained()->cascadeOnDelete();
            // The text sent, frozen: an edit of the template later reaches the next sends only.
            $table->foreignId('agreement_version_id')->constrained()->cascadeOnDelete();
            $table->foreignId('student_id')->constrained('users')->cascadeOnDelete();
            $table->string('party', 20);
            $table->string('type', 20);
            $table->text('note')->nullable();
            $table->foreignId('sent_by')->nullable()->constrained('users')->nullOnDelete();
            $table->dateTime('sent_at');
            $table->dateTime('cancelled_at')->nullable();
            // The answer, on the request itself: one answer a send.
            $table->dateTime('signed_at')->nullable();
            $table->string('signer_name')->nullable();
            $table->foreignId('signer_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('channel', 20)->nullable();
            $table->string('content_hash', 64)->nullable();
            $table->string('ip', 45)->nullable();
            $table->string('user_agent', 500)->nullable();
            $table->timestamps();

            $table->index(['student_id', 'party']);
            $table->index(['agreement_id', 'sent_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('agreement_requests');

        Schema::table('agreements', function (Blueprint $table) {
            $table->dropColumn(['kind', 'all_stages', 'author_role']);
        });
    }
};
