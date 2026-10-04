<?php

namespace App\Support;

use App\Models\ExamLevel;
use App\Models\StudentExam;
use App\Services\TeacherSyncSnapshot;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;

/**
 * A student's next exam as the box beside their name reads it: the juz count
 * of its level, its Hijri date, and how near it is.
 *
 * The tasmeeh card draws it large in its header and the tasmeeh page small
 * beside each name in its list. Both read it through this one place, so the
 * two can never disagree on which exam is next or on when one turns soon.
 *
 * The next exam is the earliest pending one whatever its date: one that
 * slipped past its day without a result is still the one awaited.
 */
class NextExamBadge
{
    /** How many days ahead an exam earns the dot, today counted as 0. */
    public const SOON_DAYS = 7;

    /**
     * The badge of each student's next exam, keyed by student, read in one go
     * for the whole list: their pending exams come in a single query, earliest
     * first, and each student keeps their first. A student with nothing
     * pending has no entry.
     *
     * @param  array<int, int>  $studentIds
     * @return Collection<int, array{juz: ?int, word: ?string, level: string, date_hijri: string, soon: bool, overdue: bool}>
     */
    public static function forStudents(array $studentIds): Collection
    {
        if ($studentIds === []) {
            return collect();
        }

        // The day as the app reads it, against today on the academy's clock.
        $today = CarbonImmutable::parse(TeacherSyncSnapshot::today());

        return StudentExam::whereIn('student_id', $studentIds)
            ->pending()
            ->with('examLevel.endAyah:id,juz_number')
            ->orderBy('date_time')
            ->orderBy('id')
            ->get()
            ->unique('student_id')
            ->mapWithKeys(fn (StudentExam $exam) => [$exam->student_id => self::of($exam, $today)]);
    }

    /**
     * @return array{juz: ?int, word: ?string, level: string, date_hijri: string, soon: bool, overdue: bool}
     */
    private static function of(StudentExam $exam, CarbonImmutable $today): array
    {
        $date = $exam->date_time->toDateString();
        $daysAway = (int) $today->diffInDays($date, false);
        $juz = $exam->examLevel?->juzCount();

        return [
            'juz' => $juz,
            'word' => $juz === null ? null : ExamLevel::juzWord($juz),
            'level' => $exam->examLevel?->name ?? '',
            'date_hijri' => HijriDate::full($date),
            'soon' => $daysAway >= 0 && $daysAway <= self::SOON_DAYS,
            'overdue' => $daysAway < 0,
        ];
    }
}
