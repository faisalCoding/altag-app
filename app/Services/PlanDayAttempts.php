<?php

namespace App\Services;

use App\Models\PlanDayAttempt;
use App\Models\Student;
use App\Models\StudentPlanDay;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Support\Facades\DB;

/**
 * The sessions a part of a plan day was recited in, and the summary of them
 * the plan day itself keeps.
 *
 * A portion marked «لم يسمع» on Sunday and recited well on Monday is two
 * sessions: Sunday keeps its «لم يسمع», Monday holds the grade it got. The plan
 * day's grade columns mirror the latest session that holds a grade — the one
 * points, reports and the student's pages already read — so nothing beyond the
 * grading screens needs to know sessions exist.
 *
 * Callers hold TasmeehChangeService::dayLockKey() while recording: both parts
 * share the day's points row.
 */
class PlanDayAttempts
{
    /**
     * The session a part was recited in on a day, if any.
     */
    public static function on(StudentPlanDay $day, string $part, string $date): ?PlanDayAttempt
    {
        // whereDate: rows written outside this class may carry a time part.
        return PlanDayAttempt::where('student_plan_day_id', $day->id)
            ->where('part', $part)
            ->whereDate('recited_on', $date)
            ->first();
    }

    /**
     * The session the plan day's summary mirrors: the latest holding a grade,
     * else the latest holding only a range.
     */
    public static function latest(StudentPlanDay $day, string $part): ?PlanDayAttempt
    {
        $attempts = PlanDayAttempt::where('student_plan_day_id', $day->id)
            ->where('part', $part)
            ->orderByDesc('recited_on')
            ->orderByDesc('id')
            ->get();

        return $attempts->first(fn (PlanDayAttempt $attempt) => $attempt->grade !== null) ?? $attempts->first();
    }

    /**
     * A grade the plan day holds with no session behind it, read as the one
     * session it stands for: on the academy's day it was graded, else on the
     * plan day's own date. Unsaved; null when the part holds nothing.
     *
     * Every grade written since sessions exist has one. This covers what was
     * written around them — seeded data, or a grade set straight on the day.
     */
    public static function fromSummary(StudentPlanDay $day, string $part): ?PlanDayAttempt
    {
        $grade = $day->{"{$part}_achievement"};
        $from = $day->{"{$part}_recited_from_ayah_id"};

        if ($grade === null && $from === null) {
            return null;
        }

        $gradedAt = $day->{"{$part}_graded_at"};

        return new PlanDayAttempt([
            'student_plan_day_id' => $day->id,
            'part' => $part,
            'recited_on' => $gradedAt
                ? CarbonImmutable::parse($gradedAt)->setTimezone(TeacherSyncSnapshot::TIMEZONE)->toDateString()
                : $day->date->toDateString(),
            'grade' => $grade,
            'recited_from_ayah_id' => $from,
            'recited_to_ayah_id' => $day->{"{$part}_recited_to_ayah_id"},
            'graded_at' => $gradedAt,
            'recorded_by' => $day->{"{$part}_recorded_by"},
        ]);
    }

    /**
     * Give a grade with no session behind it the session it stands for, before
     * anything is compared with or written beside it.
     */
    public static function adopt(StudentPlanDay $day, string $part): void
    {
        $exists = PlanDayAttempt::where('student_plan_day_id', $day->id)->where('part', $part)->exists();

        if (! $exists) {
            self::fromSummary($day, $part)?->save();
        }
    }

    /**
     * Record what a part got in one session, and bring the plan day's summary,
     * the student's points and the student's notification along.
     *
     * An emptied session leaves no row. A session whose grade changes is dated
     * anew; correcting only its range keeps the time it was graded at. The
     * range is stored as given: callers drop a range that is the day's own
     * portion first (TasmeehSnapshot::withoutScheduled).
     *
     * @param  array{0: int, 1: int}|null  $recited
     */
    public static function record(StudentPlanDay $day, string $part, string $date, string $today, ?int $grade, ?array $recited, ?int $recordedBy, ?PlanDayAttempt $attempt = null): ?PlanDayAttempt
    {
        $attempt ??= self::on($day, $part, $date);
        $gradeChanged = ($attempt?->grade) !== $grade;

        $attempt = DB::transaction(function () use ($day, $part, $date, $today, $grade, $recited, $recordedBy, $attempt, $gradeChanged) {
            if ($grade === null && $recited === null) {
                $attempt?->delete();
                $attempt = null;
            } else {
                $attempt ??= new PlanDayAttempt([
                    'student_plan_day_id' => $day->id,
                    'part' => $part,
                    'recited_on' => $date,
                ]);

                $attempt->fill([
                    'grade' => $grade,
                    'recited_from_ayah_id' => $recited[0] ?? null,
                    'recited_to_ayah_id' => $recited[1] ?? null,
                    'recorded_by' => $recordedBy,
                ]);

                if ($gradeChanged || ! $attempt->exists) {
                    $attempt->graded_at = $grade === null ? null : TasmeehChangeService::gradeTime($attempt->recited_on, $today);
                }

                $attempt->save();
            }

            if (self::summarise($day, $part)) {
                GamificationService::syncStudentPlanDayXP($day->fresh());
            }

            return $attempt;
        });

        if ($gradeChanged && $grade !== null && $grade >= 1 && ($student = $day->plan?->student)) {
            self::notify($student, $part);
        }

        return $attempt;
    }

    /**
     * Move the session the plan day shows as its grade to another day, graded
     * at the time given. Refused (false) when the part already has a session
     * on that day: two sessions never share one.
     */
    public static function move(StudentPlanDay $day, string $part, string $date, CarbonInterface $gradedAt): bool
    {
        self::adopt($day, $part);

        $session = self::latest($day, $part);

        if (! $session) {
            return false;
        }

        $taken = PlanDayAttempt::where('student_plan_day_id', $day->id)
            ->where('part', $part)
            ->whereDate('recited_on', $date)
            ->whereKeyNot($session->id)
            ->exists();

        if ($taken) {
            return false;
        }

        DB::transaction(function () use ($day, $part, $date, $gradedAt, $session) {
            $session->update(['recited_on' => $date, 'graded_at' => $gradedAt]);

            self::summarise($day, $part);
            GamificationService::syncStudentPlanDayXP($day->fresh());
        });

        return true;
    }

    /**
     * Copy the latest session into the plan day's grade columns. Returns
     * whether the grade or its date moved, which is what points are paid on.
     */
    public static function summarise(StudentPlanDay $day, string $part): bool
    {
        $latest = self::latest($day, $part);

        $day->fill([
            "{$part}_achievement" => $latest?->grade,
            "{$part}_graded_at" => $latest?->graded_at,
            "{$part}_recited_from_ayah_id" => $latest?->recited_from_ayah_id,
            "{$part}_recited_to_ayah_id" => $latest?->recited_to_ayah_id,
            "{$part}_recorded_by" => $latest?->recorded_by,
        ]);

        $paid = $day->isDirty(["{$part}_achievement", "{$part}_graded_at"]);

        $day->save();

        return $paid;
    }

    /**
     * The student hears about a grade once it is safely written. «لم يسمع» and a
     * cleared grade are not news.
     */
    private static function notify(Student $student, string $part): void
    {
        $label = $part === 'hifz' ? 'الحفظ' : 'المراجعة';

        NotificationService::notify(
            'student',
            $student->id,
            'grading',
            'تقييم جديد',
            "قام معلمك بتقييم {$label} الخاص بك",
            route('student.hifz'),
        );
    }
}
