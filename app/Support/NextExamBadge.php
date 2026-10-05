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
     * Whether the teacher may set and move exams from the tasmeeh page, which
     * turns each box into a button and draws the "+" where none is set.
     *
     * It follows the exams page: while the academy has it on for teachers.
     * The teacher app asks the same of the same switch (its canSchedule reads
     * the page from the sync snapshot), and the API takes exam changes behind
     * the teacher.api:teacher.student-exams gate. The boxes and the editor ask
     * here only, so a gate of its own later is a change to this one method.
     */
    public static function canSchedule(): bool
    {
        return RolePages::isEnabled('teacher', 'teacher.student-exams');
    }

    /**
     * The badge of each student's next exam, keyed by student, read in one go
     * for the whole list. A student with nothing pending has no entry.
     *
     * @param  array<int, int>  $studentIds
     * @return Collection<int, array{juz: ?int, word: ?string, level: string, date_hijri: string, soon: bool, overdue: bool}>
     */
    public static function forStudents(array $studentIds): Collection
    {
        // The day as the app reads it, against today on the academy's clock.
        $today = CarbonImmutable::parse(TeacherSyncSnapshot::today());

        return self::nextExams($studentIds)->map(fn (StudentExam $exam) => self::of($exam, $today));
    }

    /**
     * Each student's next exam itself, keyed by student: their pending exams
     * come in a single query and each student keeps their first.
     *
     * First by day, then by id, whatever the hour: two exams set for the
     * same day are told apart the way the teacher app tells them apart, so a
     * second one given an earlier hour on the exams page does not show here
     * while the phone shows the first.
     *
     * @param  array<int, int>  $studentIds
     * @return Collection<int, StudentExam>
     */
    public static function nextExams(array $studentIds): Collection
    {
        if ($studentIds === []) {
            return collect();
        }

        return StudentExam::whereIn('student_id', $studentIds)
            ->pending()
            ->with('examLevel.endAyah:id,juz_number')
            ->get()
            ->sortBy([
                fn (StudentExam $a, StudentExam $b) => $a->date_time->toDateString() <=> $b->date_time->toDateString(),
                fn (StudentExam $a, StudentExam $b) => $a->id <=> $b->id,
            ])
            ->unique('student_id')
            ->keyBy('student_id');
    }

    /**
     * The badge of one exam, its level loaded. Today is passed in when a whole
     * list is read, so every row is measured against the same day.
     *
     * @return array{juz: ?int, word: ?string, level: string, date_hijri: string, soon: bool, overdue: bool}
     */
    public static function of(StudentExam $exam, ?CarbonImmutable $today = null): array
    {
        $today ??= CarbonImmutable::parse(TeacherSyncSnapshot::today());
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
