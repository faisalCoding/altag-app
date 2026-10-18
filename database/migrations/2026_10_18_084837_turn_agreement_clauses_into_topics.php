<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * An agreement holds topics, each with a title and its clauses, rather than
 * one list of clauses under the agreement's title. What was written before
 * becomes one topic, named after its agreement.
 */
return new class extends Migration
{
    public function up(): void
    {
        foreach (['agreements', 'agreement_versions'] as $table) {
            Schema::table($table, fn (Blueprint $table) => $table->json('topics')->nullable());

            DB::table($table)->orderBy('id')->each(function (object $row) use ($table) {
                DB::table($table)->where('id', $row->id)->update([
                    'topics' => json_encode([['title' => $row->title, 'clauses' => json_decode($row->clauses, true) ?? []]], JSON_UNESCAPED_UNICODE),
                ]);
            });

            Schema::table($table, fn (Blueprint $table) => $table->dropColumn('clauses'));
        }
    }

    public function down(): void
    {
        foreach (['agreements', 'agreement_versions'] as $table) {
            Schema::table($table, fn (Blueprint $table) => $table->json('clauses')->nullable());

            DB::table($table)->orderBy('id')->each(function (object $row) use ($table) {
                $clauses = collect(json_decode($row->topics, true) ?? [])->flatMap(fn (array $topic) => $topic['clauses'])->values()->all();
                DB::table($table)->where('id', $row->id)->update(['clauses' => json_encode($clauses, JSON_UNESCAPED_UNICODE)]);
            });

            Schema::table($table, fn (Blueprint $table) => $table->dropColumn('topics'));
        }
    }
};
