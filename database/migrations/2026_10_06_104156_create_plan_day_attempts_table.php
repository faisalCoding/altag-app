<?php

use Carbon\CarbonImmutable;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Every session a part of a plan day was recited in, not only the last.
 *
 * A plan day used to hold one grade per part, so a portion graded «لم يسمع»
 * on Sunday and recited well on Monday kept only Monday: Sunday's «لم يسمع»
 * was written over and its date moved, as if the student had never been
 * heard that day. Each session now keeps its own row, keyed by the day it was
 * recited on, and the plan day's columns become a summary of the latest one —
 * which is what every existing reader already expects to find there.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('plan_day_attempts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('student_plan_day_id')->constrained()->cascadeOnDelete();
            $table->string('part', 10);
            $table->date('recited_on');
            // 0 is «لم يسمع»; null is a range recorded with no grade yet.
            $table->unsignedTinyInteger('grade')->nullable();
            $table->foreignId('recited_from_ayah_id')->nullable()->constrained('ayahs')->nullOnDelete();
            $table->foreignId('recited_to_ayah_id')->nullable()->constrained('ayahs')->nullOnDelete();
            $table->timestamp('graded_at')->nullable();
            $table->foreignId('recorded_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->unique(['student_plan_day_id', 'part', 'recited_on'], 'plan_day_attempts_session');
        });

        // What every plan day holds today becomes its first attempt, dated by
        // the academy's day it was graded on, or the plan day's own date when
        // it never was. What was already written over cannot be brought back.
        $now = now();

        DB::table('student_plan_days')
            ->where(fn ($q) => $q->whereNotNull('hifz_achievement')->orWhereNotNull('hifz_recited_from_ayah_id')
                ->orWhereNotNull('review_achievement')->orWhereNotNull('review_recited_from_ayah_id'))
            ->orderBy('id')
            ->chunkById(500, function ($days) use ($now) {
                $rows = [];

                foreach ($days as $day) {
                    foreach (['hifz', 'review'] as $part) {
                        $grade = $day->{"{$part}_achievement"};
                        $from = $day->{"{$part}_recited_from_ayah_id"};

                        if ($grade === null && $from === null) {
                            continue;
                        }

                        $gradedAt = $day->{"{$part}_graded_at"};

                        $rows[] = [
                            'student_plan_day_id' => $day->id,
                            'part' => $part,
                            'recited_on' => $gradedAt
                                ? CarbonImmutable::parse($gradedAt, 'UTC')->setTimezone('Asia/Riyadh')->toDateString()
                                : CarbonImmutable::parse($day->date)->toDateString(),
                            'grade' => $grade,
                            'recited_from_ayah_id' => $from,
                            'recited_to_ayah_id' => $day->{"{$part}_recited_to_ayah_id"},
                            'graded_at' => $gradedAt,
                            'recorded_by' => $day->{"{$part}_recorded_by"},
                            'created_at' => $now,
                            'updated_at' => $now,
                        ];
                    }
                }

                if ($rows !== []) {
                    DB::table('plan_day_attempts')->insert($rows);
                }
            });
    }

    public function down(): void
    {
        Schema::dropIfExists('plan_day_attempts');
    }
};
